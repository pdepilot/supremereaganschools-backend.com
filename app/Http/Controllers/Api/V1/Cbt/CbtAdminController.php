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
use App\Models\ClassSection;
use App\Models\ClassSectionOffering;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Term;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtOperationalReportingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CbtAdminController extends Controller
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly CbtOperationalReportingService $ops,
    ) {}

    public function entry(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $this->access->canManage($user) || $this->access->canMark($user) || $this->access->canProctor($user),
            403,
        );

        if (! Schema::hasTable('cbt_questions') || ! Schema::hasTable('cbt_exams')) {
            return ApiResponse::error(
                'CBT database tables are missing. Run php artisan migrate on this environment.',
                null,
                503,
            );
        }

        $canOps = $this->access->canManage($user) || $this->access->canMark($user);

        return ApiResponse::success('CBT admin entry.', [
            'capabilities' => [
                'manage' => $this->access->canManage($user),
                'mark' => $this->access->canMark($user),
                'proctor' => $this->access->canProctor($user),
            ],
            'summary' => $canOps ? $this->ops->dashboardSummary() : null,
        ]);
    }

    public function lookups(Request $request): JsonResponse
    {
        abort_unless(
            $this->access->canManage($request->user()) || $this->access->canMark($request->user()),
            403,
        );

        return ApiResponse::success('CBT admin lookups.', [
            'subjects' => Subject::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            // School year-group for question bank (e.g. Nursery 2, Basic 1).
            'classes' => SchoolClass::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            // The 18 named form groups (e.g. Nursery 2 – Awesome).
            'forms' => ClassSection::query()
                ->where('is_active', true)
                ->whereHas('schoolClass', fn ($q) => $q->where('is_active', true))
                ->with('schoolClass:id,name,sort_order')
                ->get()
                ->sortBy([
                    fn (ClassSection $row) => (int) ($row->schoolClass?->sort_order ?? 0),
                    fn (ClassSection $row) => (string) $row->name,
                ])
                ->values()
                ->map(fn (ClassSection $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'school_class_id' => $row->school_class_id,
                    'school_class' => $row->schoolClass?->name,
                ])
                ->all(),
            'academic_sessions' => AcademicSession::query()->orderByDesc('id')->get(['id', 'name', 'status']),
            'terms' => Term::query()
                ->orderByDesc('id')
                ->get(['id', 'name', 'academic_session_id']),
            // Session openings of the 18 forms — used to assign CBT exams.
            'offerings' => ClassSectionOffering::query()
                ->where('is_active', true)
                ->with(['classSection.schoolClass', 'academicSession'])
                ->whereHas('classSection', fn ($q) => $q->where('is_active', true))
                ->whereHas('classSection.schoolClass', fn ($q) => $q->where('is_active', true))
                ->get()
                ->sortBy([
                    fn (ClassSectionOffering $row) => (int) ($row->classSection?->schoolClass?->sort_order ?? 0),
                    fn (ClassSectionOffering $row) => (string) ($row->classSection?->name ?? ''),
                    fn (ClassSectionOffering $row) => (string) ($row->academicSession?->name ?? ''),
                ])
                ->values()
                ->map(fn (ClassSectionOffering $row) => [
                    'id' => $row->id,
                    'label' => trim(
                        ($row->classSection?->name ?? 'Form')
                        .( $row->academicSession?->name ? ' · '.$row->academicSession->name : '')
                    ),
                    'form' => $row->classSection?->name,
                    'class_section_id' => $row->class_section_id,
                    'school_class_id' => $row->classSection?->school_class_id,
                    'academic_session_id' => $row->academic_session_id,
                ])
                ->all(),
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
            ->with([
                'attempt.exam.subject',
                'attempt.studentProfile',
                'attempt.user',
                'access.onlinePayment',
            ])
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
            $access = $result->access;

            return array_merge($base, [
                'exam_id' => $attempt?->exam_id,
                'exam_title' => $attempt?->exam?->title,
                'subject' => $attempt?->exam?->subject?->name,
                'student_name' => $attempt?->studentProfile?->fullName(),
                'admission_number' => $attempt?->studentProfile?->admission_number,
                'attempt_status' => $attempt?->status?->value,
                'started_at' => optional($attempt?->started_at)?->toIso8601String(),
                'submitted_at' => optional($attempt?->submitted_at)?->toIso8601String(),
                'result_checker_unlocked' => $access !== null && $access->revoked_at === null,
                'result_checker_granted_at' => optional($access?->granted_at)?->toIso8601String(),
                'result_checker_payment_reference' => $access?->onlinePayment?->reference,
                'result_checker_payment_status' => $access?->onlinePayment?->status?->value,
                'result_checker_paid_at' => optional($access?->onlinePayment?->paid_at)?->toIso8601String(),
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
