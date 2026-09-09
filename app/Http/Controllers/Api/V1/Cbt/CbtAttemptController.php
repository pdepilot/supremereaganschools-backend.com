<?php

namespace App\Http\Controllers\Api\V1\Cbt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cbt\SaveCbtAnswerRequest;
use App\Http\Requests\Cbt\StartCbtAttemptRequest;
use App\Http\Requests\Cbt\SubmitCbtAttemptRequest;
use App\Http\Requests\Cbt\SyncCbtOfflineAnswersRequest;
use App\Http\Resources\Cbt\CbtAttemptResource;
use App\Http\Resources\Cbt\CbtResultResource;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtExamIntegrityService;
use App\Services\Cbt\CbtOfflinePackageService;
use App\Services\Cbt\CbtOfflineSyncService;
use App\Services\Cbt\CbtResultCheckerService;
use App\Services\Cbt\CbtSubmissionService;
use App\Enums\CbtSubmissionReason;
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
        private readonly CbtResultCheckerService $checkers,
        private readonly CbtExamIntegrityService $integrity,
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

    public function offlinePackage(Request $request, CbtAttempt $attempt, CbtOfflinePackageService $packages): JsonResponse
    {
        // Owner-only: never expose packages to admins/proctors via this continuity endpoint.
        if ((int) $attempt->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $package = $packages->buildForOwner($attempt, $request->user());

        return ApiResponse::success('Offline exam package ready.', $package);
    }

    public function sync(SyncCbtOfflineAnswersRequest $request, CbtAttempt $attempt, CbtOfflineSyncService $sync): JsonResponse
    {
        if (! $request->user()->can('answer', $attempt)) {
            abort(404);
        }

        $validated = $request->validated();
        $payload = $sync->syncAnswers(
            $attempt,
            $request->user(),
            $validated['events'],
            $validated['batch_id'] ?? null,
        );

        return ApiResponse::success('Offline answer synchronization processed.', $payload);
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

        $validated = $request->validated();
        $reason = $validated['reason'] ?? CbtSubmissionReason::StudentManual->value;

        if ($reason === CbtSubmissionReason::AutoSubmittedExamExit->value) {
            $payload = $this->integrity->autoSubmitForExamExit($attempt, $request->user(), [
                'integrity_event_id' => $validated['integrity_event_id'] ?? null,
                'correlation_id' => $validated['correlation_id'] ?? null,
                'trigger' => $validated['trigger'] ?? 'tab_hidden',
                'client_submitted_at' => $validated['client_submitted_at'] ?? null,
                'client_occurred_at' => $validated['client_submitted_at'] ?? null,
            ]);
            $result = $payload['result'];
            $message = $payload['already_submitted']
                ? 'Attempt was already submitted.'
                : 'Attempt submitted automatically due to exam exit.';
        } else {
            $result = $this->submissions->submit($attempt, $request->user(), [
                'client_submitted_at' => $validated['client_submitted_at'] ?? null,
                'reason' => $reason,
            ]);
            $message = 'Attempt submitted.';
        }

        $student = $this->access->requireStudentProfile($request->user());
        $unlocked = $this->checkers->accountHasPaidAccess($result, $student);
        $data = (new CbtResultResource($result, $unlocked))->resolve();
        $data['submission_reason'] = $result->attempt?->fresh()?->submission_reason
            ?? $attempt->fresh()?->submission_reason;

        return ApiResponse::success($message, $data);
    }

    public function autoSubmit(SubmitCbtAttemptRequest $request, CbtAttempt $attempt): JsonResponse
    {
        if (! $request->user()->can('submit', $attempt)) {
            abort(404);
        }

        $validated = $request->validated();
        $payload = $this->integrity->autoSubmitForExamExit($attempt, $request->user(), [
            'integrity_event_id' => $validated['integrity_event_id'] ?? null,
            'correlation_id' => $validated['correlation_id'] ?? null,
            'trigger' => $validated['trigger'] ?? 'tab_hidden',
            'client_submitted_at' => $validated['client_submitted_at'] ?? null,
            'client_occurred_at' => $validated['client_submitted_at'] ?? null,
            'metadata' => [
                'source' => 'auto_submit_endpoint',
            ],
        ]);

        $student = $this->access->requireStudentProfile($request->user());
        $unlocked = $this->checkers->accountHasPaidAccess($payload['result'], $student);
        $data = (new CbtResultResource($payload['result'], $unlocked))->resolve();
        $data['submission_reason'] = $attempt->fresh()?->submission_reason
            ?? CbtSubmissionReason::AutoSubmittedExamExit->value;
        $data['already_submitted'] = $payload['already_submitted'];
        $data['integrity_event_id'] = $payload['integrity_event']->event_id;

        return ApiResponse::success(
            $payload['already_submitted']
                ? 'Attempt was already submitted.'
                : 'Attempt submitted automatically due to exam exit.',
            $data
        );
    }
}
