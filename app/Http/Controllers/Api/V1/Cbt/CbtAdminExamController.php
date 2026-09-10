<?php

namespace App\Http\Controllers\Api\V1\Cbt;

use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Cbt\CbtAdminExamResource;
use App\Models\AcademicSession;
use App\Models\CbtExam;
use App\Models\CbtExamAssignment;
use App\Models\CbtExamQuestion;
use App\Models\CbtQuestion;
use App\Models\ClassSectionOffering;
use App\Models\StudentProfile;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtExamAssignmentService;
use App\Services\Cbt\CbtExamPublishService;
use App\Services\Cbt\CbtExamService;
use App\Services\Cbt\CbtExamSnapshotService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CbtAdminExamController extends Controller
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly CbtExamService $exams,
        private readonly CbtExamSnapshotService $snapshots,
        private readonly CbtExamPublishService $publish,
        private readonly CbtExamAssignmentService $assignments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->canManage($request->user()) || $this->access->canMark($request->user()), 403);

        $rows = CbtExam::query()
            ->with(['subject', 'academicSession', 'term', 'classSectionOffering.classSection', 'assignments'])
            ->withCount('attempts')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', (int) $request->input('subject_id')))
            ->when($request->filled('class_section_offering_id'), fn ($q) => $q->where('class_section_offering_id', (int) $request->input('class_section_offering_id')))
            ->when($request->filled('academic_session'), function ($q) use ($request) {
                $name = trim((string) $request->input('academic_session'));
                $q->whereHas('academicSession', fn ($s) => $s->where('name', 'like', '%'.$name.'%'));
            })
            ->when($request->filled('academic_session_id') && ! $request->filled('academic_session'), fn ($q) => $q->where('academic_session_id', (int) $request->input('academic_session_id')))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', (int) $request->input('term_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where('title', 'like', $term);
            })
            ->orderByDesc('id')
            ->paginate(20);

        return ApiResponse::success('CBT exams retrieved.', [
            'items' => CbtAdminExamResource::collection($rows)->resolve(),
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

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CbtExam::class);

        $data = $this->validatedExam($request);
        $exam = $this->exams->createDraft([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        return ApiResponse::success('Draft exam created.', (new CbtAdminExamResource($exam->fresh([
            'subject', 'academicSession', 'term', 'classSectionOffering.classSection', 'examQuestions.options', 'assignments',
        ])))->resolve(), 201);
    }

    public function show(CbtExam $exam): JsonResponse
    {
        abort_unless($this->access->canManage(request()->user()) || $this->access->canMark(request()->user()), 403);

        return ApiResponse::success('Exam retrieved.', (new CbtAdminExamResource($exam))->resolve());
    }

    public function update(Request $request, CbtExam $exam): JsonResponse
    {
        $this->authorize('update', $exam);

        $updated = $this->exams->updateDraft($exam, $this->validatedExam($request, updating: true));

        return ApiResponse::success('Draft exam updated.', (new CbtAdminExamResource($updated))->resolve());
    }

    public function attachQuestion(Request $request, CbtExam $exam): JsonResponse
    {
        $this->authorize('update', $exam);

        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:cbt_questions,id'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'marks' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
        ]);

        $question = CbtQuestion::query()->findOrFail($validated['question_id']);
        $this->snapshots->attach(
            $exam,
            $question,
            $validated['sort_order'] ?? null,
            isset($validated['marks']) ? (float) $validated['marks'] : null,
        );

        return ApiResponse::success('Question attached.', (new CbtAdminExamResource($exam->fresh()))->resolve());
    }

    public function detachQuestion(CbtExam $exam, CbtExamQuestion $examQuestion): JsonResponse
    {
        $this->authorize('update', $exam);
        abort_unless((int) $examQuestion->exam_id === (int) $exam->id, 404);

        $this->snapshots->detach($examQuestion);

        return ApiResponse::success('Question removed.', (new CbtAdminExamResource($exam->fresh()))->resolve());
    }

    public function refreshQuestion(CbtExam $exam, CbtExamQuestion $examQuestion): JsonResponse
    {
        $this->authorize('update', $exam);
        abort_unless((int) $examQuestion->exam_id === (int) $exam->id, 404);

        $this->snapshots->refreshFromBank($examQuestion);

        return ApiResponse::success('Snapshot refreshed.', (new CbtAdminExamResource($exam->fresh()))->resolve());
    }

    public function reorder(Request $request, CbtExam $exam): JsonResponse
    {
        $this->authorize('update', $exam);

        $validated = $request->validate([
            'exam_question_ids' => ['required', 'array', 'min:1'],
            'exam_question_ids.*' => ['integer', 'exists:cbt_exam_questions,id'],
        ]);

        $this->snapshots->reorder($exam, $validated['exam_question_ids']);

        return ApiResponse::success('Questions reordered.', (new CbtAdminExamResource($exam->fresh()))->resolve());
    }

    public function updateQuestionMarks(Request $request, CbtExam $exam, CbtExamQuestion $examQuestion): JsonResponse
    {
        $this->authorize('update', $exam);
        abort_unless((int) $examQuestion->exam_id === (int) $exam->id, 404);

        $validated = $request->validate([
            'marks' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->snapshots->updateSnapshotMarks($examQuestion, (float) $validated['marks']);

        return ApiResponse::success('Marks updated.', (new CbtAdminExamResource($exam->fresh()))->resolve());
    }

    public function publish(CbtExam $exam): JsonResponse
    {
        $this->authorize('publish', $exam);

        $published = $this->publish->publish($exam);

        return ApiResponse::success('Exam published and frozen.', (new CbtAdminExamResource($published))->resolve());
    }

    public function archive(CbtExam $exam): JsonResponse
    {
        $this->authorize('manage', CbtExam::class);

        $archived = $this->exams->archive($exam);

        return ApiResponse::success('Exam archived.', (new CbtAdminExamResource($archived))->resolve());
    }

    public function assign(Request $request, CbtExam $exam): JsonResponse
    {
        $this->authorize('assign', $exam);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['offering', 'student'])],
            'class_section_offering_id' => ['required_if:type,offering', 'nullable', 'integer', 'exists:class_section_offerings,id'],
            'student_profile_id' => ['required_if:type,student', 'nullable', 'integer', 'exists:student_profiles,id'],
        ]);

        if ($validated['type'] === 'offering') {
            $this->assignments->assignToOffering($exam, (int) $validated['class_section_offering_id'], $request->user()->id);
        } else {
            StudentProfile::query()->findOrFail($validated['student_profile_id']);
            $this->assignments->assignToStudent($exam, (int) $validated['student_profile_id'], $request->user()->id);
        }

        return ApiResponse::success('Assignment saved.', (new CbtAdminExamResource($exam->fresh()))->resolve());
    }

    public function unassign(CbtExam $exam, CbtExamAssignment $assignment): JsonResponse
    {
        $this->authorize('assign', $exam);
        abort_unless((int) $assignment->exam_id === (int) $exam->id, 404);

        $this->assignments->remove($assignment);

        return ApiResponse::success('Assignment removed.', (new CbtAdminExamResource($exam->fresh()))->resolve());
    }

    public function preview(CbtExam $exam): JsonResponse
    {
        $this->authorize('preview', $exam);

        $payload = (new CbtAdminExamResource($exam))->resolve();
        $payload['preview_mode'] = 'admin';
        $payload['label'] = 'ADMIN PREVIEW';

        return ApiResponse::success('Admin exam preview.', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedExam(Request $request, bool $updating = false): array
    {
        $data = $request->validate([
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'subject_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:subjects,id'],
            'class_section_offering_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:class_section_offerings,id'],
            'academic_session' => [$updating ? 'sometimes' : 'required_without:academic_session_id', 'nullable', 'string', 'max:50'],
            'academic_session_id' => [$updating ? 'sometimes' : 'required_without:academic_session', 'nullable', 'integer', 'exists:academic_sessions,id'],
            'term_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:terms,id'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'duration_minutes' => [$updating ? 'sometimes' : 'required', 'integer', 'min:1'],
            'pass_mark' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'randomize_questions' => ['sometimes', 'boolean'],
            'randomize_options' => ['sometimes', 'boolean'],
            'max_attempts' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'write_to_assessment_score' => ['sometimes', 'boolean'],
            'status' => ['prohibited'],
        ]);

        if (array_key_exists('academic_session', $data) || array_key_exists('academic_session_id', $data)) {
            $sessionName = isset($data['academic_session']) ? trim((string) $data['academic_session']) : '';
            if ($sessionName !== '') {
                $data['academic_session_id'] = $this->resolveAcademicSessionId($sessionName);
            } elseif (! empty($data['academic_session_id'])) {
                $data['academic_session_id'] = (int) $data['academic_session_id'];
            } elseif (! $updating) {
                throw ValidationException::withMessages([
                    'academic_session' => 'Enter an academic session (e.g. 2025/2026).',
                ]);
            }
            unset($data['academic_session']);
        }

        if (! empty($data['academic_session_id']) && ! empty($data['term_id'])) {
            $data['term_id'] = $this->resolveTermIdForSession((int) $data['term_id'], (int) $data['academic_session_id']);
        }

        if (! empty($data['academic_session_id']) && ! empty($data['class_section_offering_id'])) {
            $data['class_section_offering_id'] = $this->alignOfferingToSession(
                (int) $data['class_section_offering_id'],
                (int) $data['academic_session_id'],
            );
        }

        return $data;
    }

    private function resolveAcademicSessionId(string $name): int
    {
        $existing = AcademicSession::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            return (int) $existing->id;
        }

        [$startsOn, $endsOn] = $this->inferSessionDates($name);

        $session = AcademicSession::query()->create([
            'name' => $name,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'term_count' => 3,
            'status' => SessionStatus::Planned,
            'created_by' => request()->user()?->id,
        ]);

        foreach ([1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'] as $number => $termName) {
            Term::query()->create([
                'academic_session_id' => $session->id,
                'name' => $termName,
                'term_number' => $number,
                'status' => SessionStatus::Planned,
            ]);
        }

        return (int) $session->id;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function inferSessionDates(string $name): array
    {
        if (preg_match('/(\d{4})\s*[\/\-]\s*(\d{4})/', $name, $matches) === 1) {
            $startYear = (int) $matches[1];
            $endYear = (int) $matches[2];

            return [
                sprintf('%04d-09-01', $startYear),
                sprintf('%04d-07-31', $endYear),
            ];
        }

        $year = (int) Carbon::now()->year;

        return [
            sprintf('%04d-09-01', $year),
            sprintf('%04d-07-31', $year + 1),
        ];
    }

    private function resolveTermIdForSession(int $termId, int $sessionId): int
    {
        $term = Term::query()->find($termId);
        if ($term === null) {
            throw ValidationException::withMessages([
                'term_id' => 'A valid term is required.',
            ]);
        }

        if ((int) $term->academic_session_id === $sessionId) {
            return (int) $term->id;
        }

        $mapped = Term::query()
            ->where('academic_session_id', $sessionId)
            ->where(function ($q) use ($term): void {
                $q->where('term_number', $term->term_number)
                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower((string) $term->name)]);
            })
            ->orderBy('term_number')
            ->first();

        if ($mapped) {
            return (int) $mapped->id;
        }

        throw ValidationException::withMessages([
            'term_id' => 'Choose a term that matches the academic session (First, Second, or Third Term).',
        ]);
    }

    private function alignOfferingToSession(int $offeringId, int $sessionId): int
    {
        $offering = ClassSectionOffering::query()->with('subjectOfferings')->find($offeringId);
        if ($offering === null) {
            throw ValidationException::withMessages([
                'class_section_offering_id' => 'A valid class section offering is required.',
            ]);
        }

        if ((int) $offering->academic_session_id === $sessionId) {
            return (int) $offering->id;
        }

        $aligned = ClassSectionOffering::query()->firstOrCreate(
            [
                'class_section_id' => $offering->class_section_id,
                'academic_session_id' => $sessionId,
            ],
            [
                'campus_id' => $offering->campus_id,
                'capacity' => $offering->capacity,
                'is_active' => true,
            ],
        );

        if ($aligned->wasRecentlyCreated || ! $aligned->subjectOfferings()->exists()) {
            foreach ($offering->subjectOfferings as $subjectOffering) {
                SubjectOffering::query()->firstOrCreate([
                    'class_section_offering_id' => $aligned->id,
                    'subject_id' => $subjectOffering->subject_id,
                ]);
            }
        }

        if (! $aligned->is_active) {
            $aligned->update(['is_active' => true]);
        }

        return (int) $aligned->id;
    }
}
