<?php

namespace App\Http\Controllers\Api\V1\Cbt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cbt\SaveCbtAnswerRequest;
use App\Http\Requests\Cbt\StartCbtAttemptRequest;
use App\Http\Requests\Cbt\SubmitCbtAttemptRequest;
use App\Http\Resources\Cbt\CbtAttemptResource;
use App\Http\Resources\Cbt\CbtResultResource;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtSubmissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CbtAttemptController extends Controller
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly CbtAttemptService $attempts,
        private readonly CbtAnswerService $answers,
        private readonly CbtSubmissionService $submissions,
    ) {}

    public function start(StartCbtAttemptRequest $request, CbtExam $exam): JsonResponse
    {
        $this->authorize('take', $exam);

        $user = $request->user();
        $student = $this->access->requireStudentProfile($user);

        $attempt = $this->attempts->start($exam, $user, $student, [
            'uuid' => $request->validated('uuid'),
            'mode' => $request->validated('mode'),
            'device_id' => $request->validated('device_id'),
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success('Attempt started.', (new CbtAttemptResource($attempt))->resolve(), 201);
    }

    public function show(Request $request, CbtAttempt $attempt): JsonResponse
    {
        if (! $request->user()->can('view', $attempt)) {
            abort(404);
        }

        return ApiResponse::success('Attempt retrieved.', (new CbtAttemptResource($attempt))->resolve());
    }

    public function saveAnswer(SaveCbtAnswerRequest $request, CbtAttempt $attempt): JsonResponse
    {
        if (! $request->user()->can('answer', $attempt)) {
            abort(404);
        }

        $answer = $this->answers->save($attempt, $request->user(), $request->validated());

        return ApiResponse::success('Answer saved.', [
            'attempt_id' => $attempt->id,
            'exam_question_id' => $answer->exam_question_id,
            'selected_exam_option_id' => $answer->selected_exam_option_id,
            'answered_at' => optional($answer->answered_at)?->toIso8601String(),
        ]);
    }

    public function submit(SubmitCbtAttemptRequest $request, CbtAttempt $attempt): JsonResponse
    {
        if (! $request->user()->can('submit', $attempt)) {
            abort(404);
        }

        $result = $this->submissions->submit($attempt, $request->user(), [
            'client_submitted_at' => $request->validated('client_submitted_at'),
        ]);

        return ApiResponse::success('Attempt submitted.', (new CbtResultResource($result))->resolve());
    }
}
