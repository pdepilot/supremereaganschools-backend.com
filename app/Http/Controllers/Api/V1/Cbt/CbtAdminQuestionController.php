<?php

namespace App\Http\Controllers\Api\V1\Cbt;

use App\Enums\CbtQuestionDifficulty;
use App\Enums\CbtQuestionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Cbt\CbtAdminQuestionResource;
use App\Models\CbtQuestion;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtQuestionBankService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CbtAdminQuestionController extends Controller
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly CbtQuestionBankService $questions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CbtQuestion::class);

        $rows = CbtQuestion::query()
            ->with(['options', 'schoolClass', 'subject'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner
                    ->where('stem', 'like', $term)
                    ->orWhere('topic', 'like', $term));
            })
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', (int) $request->input('subject_id')))
            ->when($request->filled('school_class_id'), fn ($q) => $q->where('school_class_id', (int) $request->input('school_class_id')))
            ->when($request->filled('difficulty'), fn ($q) => $q->where('difficulty', $request->string('difficulty')))
            ->when($request->has('is_active') && $request->input('is_active') !== '', fn ($q) => $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN)))
            ->orderByDesc('id')
            ->paginate(20);

        return ApiResponse::success('CBT questions retrieved.', [
            'items' => CbtAdminQuestionResource::collection($rows)->resolve(),
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
        $this->authorize('create', CbtQuestion::class);

        $data = $this->validatedQuestion($request);
        $question = $this->questions->create([
            ...$data['attributes'],
            'created_by' => $request->user()->id,
        ], $data['options'] ?? []);

        return ApiResponse::success('Question created.', (new CbtAdminQuestionResource($question))->resolve(), 201);
    }

    public function show(CbtQuestion $question): JsonResponse
    {
        $this->authorize('view', $question);

        return ApiResponse::success('Question retrieved.', (new CbtAdminQuestionResource($question))->resolve());
    }

    public function update(Request $request, CbtQuestion $question): JsonResponse
    {
        $this->authorize('update', $question);

        $data = $this->validatedQuestion($request, updating: true);
        $updated = $this->questions->update($question, $data['attributes'], $data['options']);

        return ApiResponse::success('Question updated.', (new CbtAdminQuestionResource($updated))->resolve());
    }

    public function setActive(Request $request, CbtQuestion $question): JsonResponse
    {
        $this->authorize('update', $question);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $updated = $this->questions->setActive($question, (bool) $validated['is_active']);

        return ApiResponse::success('Question status updated.', (new CbtAdminQuestionResource($updated))->resolve());
    }

    /**
     * @return array{attributes: array<string, mixed>, options: list<array<string, mixed>>}
     */
    private function validatedQuestion(Request $request, bool $updating = false): array
    {
        $validated = $request->validate([
            'school_class_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:school_classes,id'],
            'subject_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:subjects,id'],
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'difficulty' => ['sometimes', 'nullable', Rule::enum(CbtQuestionDifficulty::class)],
            'type' => ['sometimes', 'nullable', Rule::enum(CbtQuestionType::class)],
            'stem' => [$updating ? 'sometimes' : 'required', 'string'],
            'marks' => ['sometimes', 'numeric', 'gt:0'],
            'explanation' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => [$updating ? 'sometimes' : 'required', 'array', 'min:2'],
            'options.*.label' => ['sometimes', 'nullable', 'string', 'max:8'],
            'options.*.body' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['required_with:options', 'boolean'],
            'options.*.sort_order' => ['sometimes', 'integer', 'min:1'],
        ]);

        if (array_key_exists('difficulty', $validated) && blank($validated['difficulty'])) {
            $validated['difficulty'] = CbtQuestionDifficulty::Medium->value;
        }

        if (array_key_exists('type', $validated) && blank($validated['type'])) {
            $validated['type'] = CbtQuestionType::Mcq->value;
        }

        $options = array_key_exists('options', $validated) ? $validated['options'] : null;
        unset($validated['options']);

        return [
            'attributes' => $validated,
            'options' => $options,
        ];
    }
}
