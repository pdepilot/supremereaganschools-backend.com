<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assessments\StoreResultCommentsRequest;
use App\Models\AssessmentScore;
use App\Services\AssessmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    public function __construct(private readonly AssessmentService $assessments) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssessmentScore::class);

        $payload = $this->assessments->resultsForStudent(
            $request->filled('student_profile_id') ? $request->integer('student_profile_id') : null,
            $request->filled('term_id') ? $request->integer('term_id') : null,
            $request->filled('academic_session_id') ? $request->integer('academic_session_id') : null,
            $request->user(),
        );

        return ApiResponse::success('Results retrieved.', $payload);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssessmentScore::class);

        $payload = $this->assessments->resultsForStudent(
            $request->filled('student_profile_id') ? $request->integer('student_profile_id') : null,
            $request->filled('term_id') ? $request->integer('term_id') : null,
            $request->filled('academic_session_id') ? $request->integer('academic_session_id') : null,
            $request->user(),
        );

        return ApiResponse::success('Result summary retrieved.', [
            'student_profile_id' => $payload['student_profile_id'],
            'student_name' => $payload['student_name'],
            'form' => $payload['form'],
            'term_name' => $payload['term_name'],
            'session_name' => $payload['session_name'],
            'average' => $payload['average'],
            'class_position' => $payload['class_position'],
            'class_size' => $payload['class_size'],
            'highest' => $payload['highest'],
        ]);
    }

    public function comments(StoreResultCommentsRequest $request): JsonResponse
    {
        $this->authorize('create', AssessmentScore::class);

        return ApiResponse::success('Report comments saved.', $this->assessments->saveComments(
            $request->integer('enrollment_id'),
            $request->integer('term_id'),
            $request->safe()->only(['class_teacher_comment', 'principal_comment']),
            $request->user(),
        ));
    }
}
