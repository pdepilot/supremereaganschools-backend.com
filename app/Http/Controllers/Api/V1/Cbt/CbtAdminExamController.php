<?php

namespace App\Http\Controllers\Api\V1\Cbt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cbt\CbtAdminExamResource;
use App\Models\CbtExam;
use App\Models\CbtExamAssignment;
use App\Models\CbtExamQuestion;
use App\Models\CbtQuestion;
use App\Models\StudentProfile;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtExamAssignmentService;
use App\Services\Cbt\CbtExamPublishService;
use App\Services\Cbt\CbtExamService;
use App\Services\Cbt\CbtExamSnapshotService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            ->when($request->filled('academic_session_id'), fn ($q) => $q->where('academic_session_id', (int) $request->input('academic_session_id')))
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
        return $request->validate([
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'subject_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:subjects,id'],
            'class_section_offering_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:class_section_offerings,id'],
            'academic_session_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:academic_sessions,id'],
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
    }
}
