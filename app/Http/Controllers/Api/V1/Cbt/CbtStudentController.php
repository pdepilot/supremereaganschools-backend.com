<?php

namespace App\Http\Controllers\Api\V1\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\CbtExamStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Cbt\CbtResultResource;
use App\Http\Resources\Cbt\CbtStudentExamResource;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtResult;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtExamAssignmentService;
use App\Services\Cbt\CbtResultCheckerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CbtStudentController extends Controller
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly CbtExamAssignmentService $assignments,
        private readonly CbtResultCheckerService $checkers,
    ) {}

    public function exams(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CbtExam::class);

        $user = $request->user();
        $student = $this->access->requireStudentProfile($user);

        $exams = CbtExam::query()
            ->with(['subject'])
            ->where('status', CbtExamStatus::Published)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get()
            ->filter(fn (CbtExam $exam) => $this->assignments->isStudentEligible($exam, $student))
            ->values();

        $attempts = CbtAttempt::query()
            ->where('student_profile_id', $student->id)
            ->whereIn('exam_id', $exams->pluck('id'))
            ->where('status', '!=', CbtAttemptStatus::Void)
            ->with('result')
            ->get()
            ->groupBy('exam_id');

        return ApiResponse::success('CBT exams retrieved.', [
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'admission_number' => $student->admission_number,
            ],
            'exams' => $exams->map(function (CbtExam $exam) use ($attempts, $student) {
                $examAttempts = $attempts->get($exam->id, collect());
                $active = $examAttempts->firstWhere('status', CbtAttemptStatus::InProgress);
                $used = $examAttempts->count();
                $submitted = $examAttempts->firstWhere('status', CbtAttemptStatus::Submitted);
                $unlocked = $submitted?->result
                    ? $this->checkers->accountHasPaidAccess($submitted->result, $student)
                    : false;

                return [
                    'id' => $exam->id,
                    'title' => $exam->title,
                    'subject' => $exam->subject?->name,
                    'duration_minutes' => $exam->duration_minutes,
                    'starts_at' => optional($exam->starts_at)?->toIso8601String(),
                    'ends_at' => optional($exam->ends_at)?->toIso8601String(),
                    'question_count' => $exam->question_count,
                    'max_score' => (string) $exam->max_score,
                    'pass_mark' => $exam->pass_mark !== null ? (string) $exam->pass_mark : null,
                    'max_attempts' => $exam->max_attempts,
                    'attempts_used' => $used,
                    'attempts_remaining' => max(0, (int) $exam->max_attempts - $used),
                    'active_attempt_id' => $active?->id,
                    'active_attempt_uuid' => $active?->uuid,
                    'latest_result' => $submitted?->result
                        ? (new CbtResultResource($submitted->result, $unlocked))->resolve()
                        : null,
                    'attempt_status' => $active
                        ? 'in_progress'
                        : ($submitted ? 'submitted' : 'not_started'),
                ];
            })->all(),
        ]);
    }

    public function showExam(Request $request, CbtExam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        if ($exam->status !== CbtExamStatus::Published || ! $exam->is_active) {
            abort(404);
        }

        $user = $request->user();
        $student = $this->access->requireStudentProfile($user);

        $examAttempts = CbtAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('student_profile_id', $student->id)
            ->where('status', '!=', CbtAttemptStatus::Void)
            ->with('result')
            ->get();

        $active = $examAttempts->firstWhere('status', CbtAttemptStatus::InProgress);
        $used = $examAttempts->count();

        $payload = (new CbtStudentExamResource($exam))->resolve();
        $payload['attempts_used'] = $used;
        $payload['attempts_remaining'] = max(0, (int) $exam->max_attempts - $used);
        $payload['active_attempt_id'] = $active?->id;
        $payload['active_attempt_uuid'] = $active?->uuid;
        $payload['can_start'] = $active === null && $used < (int) $exam->max_attempts;
        $payload['can_resume'] = $active !== null;

        return ApiResponse::success('CBT exam retrieved.', $payload);
    }

    public function results(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CbtResult::class);

        $user = $request->user();
        $student = $this->access->requireStudentProfile($user);

        $results = CbtResult::query()
            ->whereHas('attempt', fn ($q) => $q
                ->where('user_id', $user->id)
                ->where('student_profile_id', $student->id))
            ->with(['attempt.exam.subject'])
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success('CBT results retrieved.', [
            'details_require_payment' => $this->checkers->detailsRequirePayment(),
            'pricing' => $this->checkers->pricing(),
            'results' => $results->map(function (CbtResult $result) use ($student) {
                $unlocked = $this->checkers->accountHasPaidAccess($result, $student);

                return (new CbtResultResource($result, $unlocked))->resolve();
            })->all(),
        ]);
    }
}
