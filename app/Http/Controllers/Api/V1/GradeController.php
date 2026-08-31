<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assessments\BulkAssessmentScoreRequest;
use App\Http\Requests\Assessments\StoreAssessmentScoreRequest;
use App\Http\Resources\Assessments\AssessmentScoreResource;
use App\Models\AssessmentScore;
use App\Services\AssessmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    public function __construct(private readonly AssessmentService $assessments) {}

    public function contexts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssessmentScore::class);

        return ApiResponse::success('Grade contexts retrieved.', $this->assessments->contexts($request->user()));
    }

    public function register(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssessmentScore::class);

        $validated = $request->validate([
            'class_section_offering_id' => ['required', 'integer', 'exists:class_section_offerings,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'academic_session_id' => ['nullable', 'integer', 'exists:academic_sessions,id'],
            'assessment_type_id' => ['nullable', 'integer', 'exists:assessment_types,id'],
            'view' => ['nullable', 'in:scores,results'],
        ]);

        $finalView = ($validated['view'] ?? null) === 'results' || empty($validated['assessment_type_id']);

        $payload = $this->assessments->register(
            (int) $validated['class_section_offering_id'],
            (int) $validated['subject_id'],
            isset($validated['term_id']) ? (int) $validated['term_id'] : null,
            isset($validated['academic_session_id']) ? (int) $validated['academic_session_id'] : null,
            isset($validated['assessment_type_id']) ? (int) $validated['assessment_type_id'] : null,
            $finalView,
            $request->user(),
        );

        return ApiResponse::success('Grade register retrieved.', $payload);
    }

    public function store(StoreAssessmentScoreRequest $request): JsonResponse
    {
        $score = $this->assessments->record($request->validated(), $request->user());

        return ApiResponse::success('Score recorded.', (new AssessmentScoreResource($score))->resolve(), 201);
    }

    public function bulk(BulkAssessmentScoreRequest $request): JsonResponse
    {
        $scores = $this->assessments->bulk($request->validated(), $request->user());

        return ApiResponse::success(
            'Scores recorded.',
            AssessmentScoreResource::collection($scores)->resolve(),
        );
    }
}
