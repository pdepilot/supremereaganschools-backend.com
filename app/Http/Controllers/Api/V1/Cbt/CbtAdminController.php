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
use App\Models\SubjectOffering;
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

        $activeOfferingQuery = function ($q): void {
            $q->where('is_active', true)
                ->whereHas('classSection', fn ($s) => $s->where('is_active', true))
                ->whereHas('classSection.schoolClass', fn ($c) => $c->where('is_active', true));
        };

        $bookSubjectIds = SubjectOffering::query()
            ->whereHas('classSectionOffering', $activeOfferingQuery)
            ->pluck('subject_id')
            ->unique()
            ->values();

        $subjects = Subject::query()
            ->where('is_active', true)
            ->whereIn('id', $bookSubjectIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $subjectsByClass = [];
        SchoolClass::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->each(function (SchoolClass $class) use (&$subjectsByClass, $activeOfferingQuery): void {
                $ids = SubjectOffering::query()
                    ->whereHas('classSectionOffering', function ($q) use ($class, $activeOfferingQuery): void {
                        $activeOfferingQuery($q);
                        $q->whereHas('classSection', fn ($s) => $s->where('school_class_id', $class->id));
                    })
                    ->pluck('subject_id')
                    ->unique()
                    ->all();

                $subjectsByClass[(string) $class->id] = Subject::query()
                    ->where('is_active', true)
                    ->whereIn('id', $ids)
                    ->orderBy('name')
                    ->get(['id', 'name', 'code'])
                    ->values()
                    ->all();
            });

        $subjectsByOffering = [];
        ClassSectionOffering::query()
            ->where('is_active', true)
            ->whereHas('classSection', fn ($s) => $s->where('is_active', true))
            ->whereHas('classSection.schoolClass', fn ($c) => $c->where('is_active', true))
            ->with(['subjects' => fn ($q) => $q->where('subjects.is_active', true)->orderBy('name')])
            ->each(function (ClassSectionOffering $offering) use (&$subjectsByOffering): void {
                $subjectsByOffering[(string) $offering->id] = $offering->subjects
                    ->map(fn (Subject $subject) => [
                        'id' => $subject->id,
                        'name' => $subject->name,
                        'code' => $subject->code,
                    ])
                    ->values()
                    ->all();
            });

        return ApiResponse::success('CBT admin lookups.', [
            // School-book subjects only (offered on active forms).
            'subjects' => $subjects,
            'subjects_by_school_class' => $subjectsByClass,
            'subjects_by_offering' => $subjectsByOffering,
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
                        .($row->academicSession?->name ? ' · '.$row->academicSession->name : '')
                    ),
                    'form' => $row->classSection?->name,
                    'class_section_id' => $row->class_section_id,
                    'school_class_id' => $row->classSection?->school_class_id,
                    'academic_session_id' => $row->academic_session_id,
                    'academic_session' => $row->academicSession?->name,
                ])
                ->all(),
            'difficulties' => array_map(fn (CbtQuestionDifficulty $case) => $case->value, CbtQuestionDifficulty::cases()),
            'question_types' => array_map(fn (CbtQuestionType $case) => $case->value, CbtQuestionType::cases()),
            'exam_statuses' => array_map(fn (CbtExamStatus $case) => $case->value, CbtExamStatus::cases()),
        ]);
    }

    public function storeSubject(Request $request): JsonResponse
    {
        abort_unless($this->access->canManage($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
            'school_class_id' => ['nullable', 'required_without:class_section_offering_id', 'integer', 'exists:school_classes,id'],
            'class_section_offering_id' => ['nullable', 'required_without:school_class_id', 'integer', 'exists:class_section_offerings,id'],
        ]);

        $name = trim($data['name']);
        $preferredCode = isset($data['code']) && trim((string) $data['code']) !== ''
            ? strtoupper(trim((string) $data['code']))
            : null;

        $subject = Subject::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $subject && $preferredCode) {
            $subject = Subject::query()->where('code', $preferredCode)->first();
        }

        $created = false;
        if ($subject) {
            $subject->fill([
                'name' => $name,
                'is_active' => true,
            ]);
            if ($preferredCode && ! Subject::query()->where('code', $preferredCode)->whereKeyNot($subject->id)->exists()) {
                $subject->code = $preferredCode;
            }
            $subject->save();
        } else {
            $subject = Subject::query()->create([
                'name' => $name,
                'code' => $this->uniqueSubjectCode($name, $preferredCode),
                'is_active' => true,
            ]);
            $created = true;
        }

        $offeringIds = [];
        if (! empty($data['class_section_offering_id'])) {
            $offeringIds[] = (int) $data['class_section_offering_id'];
        } elseif (! empty($data['school_class_id'])) {
            $offeringIds = ClassSectionOffering::query()
                ->where('is_active', true)
                ->whereHas('classSection', function ($q) use ($data): void {
                    $q->where('school_class_id', (int) $data['school_class_id'])
                        ->where('is_active', true);
                })
                ->pluck('id')
                ->all();
        }

        foreach ($offeringIds as $offeringId) {
            SubjectOffering::query()->firstOrCreate([
                'class_section_offering_id' => $offeringId,
                'subject_id' => $subject->id,
            ]);
        }

        return ApiResponse::success(
            $created ? 'Subject created for CBT.' : 'Subject ready for CBT.',
            [
                'subject' => [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'code' => $subject->code,
                ],
                'attached_offering_ids' => array_values(array_map('intval', $offeringIds)),
            ],
            $created ? 201 : 200,
        );
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

    private function uniqueSubjectCode(string $name, ?string $preferred = null): string
    {
        $base = $preferred
            ?: strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?: 'SUB', 0, 8));
        $base = substr($base !== '' ? $base : 'SUB', 0, 16);

        $candidate = $base;
        $suffix = 2;
        while (Subject::query()->where('code', $candidate)->exists()) {
            $candidate = substr($base, 0, 12).$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
