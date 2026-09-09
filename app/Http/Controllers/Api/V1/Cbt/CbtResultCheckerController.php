<?php

namespace App\Http\Controllers\Api\V1\Cbt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cbt\CbtResultResource;
use App\Http\Resources\Payments\OnlinePaymentResource;
use App\Models\CbtResult;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtMarkingService;
use App\Services\Cbt\CbtResultCheckerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CbtResultCheckerController extends Controller
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly CbtResultCheckerService $checkers,
        private readonly CbtMarkingService $marking,
    ) {}

    public function pricing(): JsonResponse
    {
        return ApiResponse::success('Result Checker pricing retrieved.', [
            ...$this->checkers->pricing(),
            'paystack_public_key' => config('services.paystack.public_key'),
        ]);
    }

    public function unlock(Request $request, CbtResult $result): JsonResponse
    {
        $user = $request->user();
        $student = $this->access->requireStudentProfile($user);

        $this->authorize('view', $result);
        $this->checkers->assertOwnsResult($result, $user, $student);

        // Client amount/currency are ignored if present.
        $request->validate([
            'amount' => ['sometimes', 'nullable'],
            'amount_kobo' => ['sometimes', 'nullable'],
            'currency' => ['sometimes', 'nullable'],
        ]);

        $started = $this->checkers->beginUnlock($result, $user, $student);

        if ($started['unlocked']) {
            return ApiResponse::success('Detailed result already unlocked.', [
                'unlocked' => true,
                'result_id' => $result->id,
                'payment' => $started['payment']
                    ? (new OnlinePaymentResource($started['payment']))->resolve()
                    : null,
            ]);
        }

        return ApiResponse::success('Result Checker checkout started.', [
            'unlocked' => false,
            'result_id' => $result->id,
            'reference' => $started['payment']?->reference,
            'amount_kobo' => $started['payment']?->amount_kobo,
            'currency' => $started['payment']?->currency,
            'authorization_url' => $started['authorization_url'],
            'payment' => $started['payment']
                ? (new OnlinePaymentResource($started['payment']))->resolve()
                : null,
        ]);
    }

    public function access(Request $request, CbtResult $result): JsonResponse
    {
        $user = $request->user();
        $student = $this->access->requireStudentProfile($user);

        $this->authorize('view', $result);
        $this->checkers->assertOwnsResult($result, $user, $student);

        $access = $this->checkers->activeAccessFor($result, $student);
        $unlocked = $this->checkers->accountHasPaidAccess($result, $student);

        return ApiResponse::success('Result Checker access retrieved.', [
            'result_id' => $result->id,
            'unlocked' => $unlocked,
            'result_available' => true,
            'details_require_payment' => $this->checkers->detailsRequirePayment(),
            'pricing' => $this->checkers->pricing(),
            'granted_at' => optional($access?->granted_at)?->toIso8601String(),
            'payment' => $access?->onlinePayment
                ? (new OnlinePaymentResource($access->onlinePayment))->resolve()
                : null,
        ]);
    }

    public function showResult(Request $request, CbtResult $result): JsonResponse
    {
        $this->authorize('view', $result);
        $user = $request->user();
        $student = $this->access->requireStudentProfile($user);
        $this->checkers->assertOwnsResult($result, $user, $student);

        $unlocked = $this->checkers->accountHasPaidAccess($result, $student);

        return ApiResponse::success('CBT result retrieved.', [
            'result' => (new CbtResultResource($result))->toSummary($unlocked),
            'details_unlocked' => $unlocked,
            'details_require_payment' => $this->checkers->detailsRequirePayment(),
            'pricing' => $this->checkers->pricing(),
        ]);
    }

    public function detailedResult(Request $request, CbtResult $result): JsonResponse
    {
        $this->authorize('view', $result);
        $user = $request->user();
        $student = $this->access->requireStudentProfile($user);
        $this->checkers->assertOwnsResult($result, $user, $student);

        if (! $this->checkers->accountHasPaidAccess($result, $student)) {
            return ApiResponse::error('Purchase a Result Checker to unlock detailed results.', status: 402);
        }

        $result->loadMissing([
            'attempt.exam.subject',
            'attempt.exam.academicSession',
            'attempt.exam.term',
            'attempt.answers',
        ]);

        $breakdown = $this->safeBreakdown($result->attempt);
        $access = $this->checkers->activeAccessFor($result, $student);

        return ApiResponse::success('Detailed CBT result retrieved.', [
            'result' => (new CbtResultResource($result))->toDetailed($breakdown, $access),
            'details_unlocked' => true,
            'payment' => $access?->onlinePayment
                ? (new OnlinePaymentResource($access->onlinePayment))->resolve()
                : null,
        ]);
    }

    /**
     * @return array{attempted: int, correct: int, incorrect: int, unanswered: int}
     */
    private function safeBreakdown(?\App\Models\CbtAttempt $attempt): array
    {
        if ($attempt === null) {
            return ['attempted' => 0, 'correct' => 0, 'incorrect' => 0, 'unanswered' => 0];
        }

        $attempt->loadMissing(['exam.examQuestions.options', 'answers']);
        $marked = $this->marking->mark($attempt);
        $details = $marked['per_question'] ?? [];

        $correct = 0;
        $incorrect = 0;
        $unanswered = 0;
        $answers = $attempt->answers->keyBy('exam_question_id');
        foreach ($details as $row) {
            $answer = $answers->get($row['exam_question_id'] ?? 0);
            if (! $answer?->selected_exam_option_id) {
                $unanswered++;
            } elseif (! empty($row['correct'])) {
                $correct++;
            } else {
                $incorrect++;
            }
        }

        return [
            'attempted' => $correct + $incorrect,
            'correct' => $correct,
            'incorrect' => $incorrect,
            'unanswered' => $unanswered,
        ];
    }
}
