<?php

namespace App\Http\Controllers\Api\V1\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\CbtExamStatus;
use App\Enums\CbtQuestionDifficulty;
use App\Enums\CbtQuestionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Cbt\CbtResultResource;
use App\Models\AcademicSession;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtQuestion;
use App\Models\CbtResult;
use App\Models\ClassSectionOffering;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Term;
use App\Services\Cbt\CbtAccessService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CbtAdminController extends Controller
{
    public function __construct(private readonly CbtAccessService $access) {}

    public function entry(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $this->access->canManage($user) || $this->access->canMark($user) || $this->access->canProctor($user),
            403,
        );

        return ApiResponse::success('CBT admin entry.', [
            'capabilities' => [
                'manage' => $this->access->canManage($user),
                'mark' => $this->access->canMark($user),
                'proctor' => $this->access->canProctor($user),
            ],
            'summary' => $this->access->canManage($user) || $this->access->canMark($user) ? [
                'questions' => CbtQuestion::query()->count(),
                'draft_exams' => CbtExam::query()->where('status', CbtExamStatus::Draft)->count(),
                'published_exams' => CbtExam::query()->where('status', CbtExamStatus::Published)->count(),
                'attempts' => CbtAttempt::query()->count(),
                'submitted_attempts' => CbtAttempt::query()->where('status', CbtAttemptStatus::Submitted)->count(),
                'results' => CbtResult::query()->count(),
            ] : null,
        ]);
    }

    public function lookups(Request $request): JsonResponse
    {
        abort_unless(
            $this->access->canManage($request->user()) || $this->access->canMark($request->user()),
            403,
        );

        return ApiResponse::success('CBT admin lookups.', [
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name', 'code']),
            'classes' => SchoolClass::query()->orderBy('name')->get(['id', 'name']),
            'academic_sessions' => AcademicSession::query()->orderByDesc('id')->get(['id', 'name', 'status']),
            'terms' => Term::query()
                ->orderByDesc('id')
                ->get(['id', 'name', 'academic_session_id']),
            'offerings' => ClassSectionOffering::query()
                ->with(['classSection.schoolClass', 'academicSession'])
                ->orderByDesc('id')
                ->limit(300)
                ->get()
                ->map(fn (ClassSectionOffering $row) => [
                    'id' => $row->id,
                    'label' => trim(($row->classSection?->schoolClass?->name ?? 'Class').' / '.($row->classSection?->name ?? 'Section').' · '.($row->academicSession?->name ?? '')),
                    'class_section_id' => $row->class_section_id,
                    'school_class_id' => $row->classSection?->school_class_id,
                    'academic_session_id' => $row->academic_session_id,
                ])->values()->all(),
            'difficulties' => array_map(fn (CbtQuestionDifficulty $case) => $case->value, CbtQuestionDifficulty::cases()),
            'question_types' => array_map(fn (CbtQuestionType $case) => $case->value, CbtQuestionType::cases()),
            'exam_statuses' => array_map(fn (CbtExamStatus $case) => $case->value, CbtExamStatus::cases()),
        ]);
    }

    public function students(Request $request): JsonResponse
    {
        abort_unless($this->access->canManage($request->user()), 403);

        $term = trim((string) $request->input('q', ''));
        if (strlen($term) < 2) {
            return ApiResponse::error('Provide at least two characters to search students.', null, 422);
        }

        $rows = StudentProfile::query()
            ->where(function ($q) use ($term) {
                $like = '%'.$term.'%';
                $q->where('admission_number', 'like', $like)
                    ->orWhere('surname', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('other_names', 'like', $like);
            })
            ->orderBy('surname')
            ->orderBy('first_name')
            ->limit(25)
            ->get(['id', 'admission_number', 'surname', 'first_name', 'other_names']);

        return ApiResponse::success('Students for CBT assignment.', [
            'items' => $rows->map(fn (StudentProfile $student) => [
                'id' => $student->id,
                'admission_number' => $student->admission_number,
                'name' => $student->fullName(),
            ])->values()->all(),
        ]);
    }

    public function results(Request $request): JsonResponse
    {
        $this->authorize('viewAdmin', CbtResult::class);

        $rows = CbtResult::query()
            ->with(['attempt.exam.subject', 'attempt.studentProfile', 'attempt.user'])
            ->when($request->filled('exam_id'), fn ($q) => $q->whereHas('attempt', fn ($a) => $a->where('exam_id', (int) $request->input('exam_id'))))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->whereHas('attempt.studentProfile', fn ($s) => $s
                    ->where('surname', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('admission_number', 'like', $term));
            })
            ->orderByDesc('id')
            ->paginate(20);

        $items = $rows->getCollection()->map(function (CbtResult $result) {
            $base = (new CbtResultResource($result))->resolve();
            $attempt = $result->attempt;

            return array_merge($base, [
                'exam_id' => $attempt?->exam_id,
                'exam_title' => $attempt?->exam?->title,
                'subject' => $attempt?->exam?->subject?->name,
                'student_name' => $attempt?->studentProfile?->fullName(),
                'admission_number' => $attempt?->studentProfile?->admission_number,
                'attempt_status' => $attempt?->status?->value,
                'started_at' => optional($attempt?->started_at)?->toIso8601String(),
                'submitted_at' => optional($attempt?->submitted_at)?->toIso8601String(),
            ]);
        })->values()->all();

        return ApiResponse::success('CBT admin results retrieved.', [
            'items' => $items,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
                'total' => $rows->total(),
            ],
        ]);
    }
}
