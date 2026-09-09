<?php

namespace App\Services\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\OnlinePaymentPurpose;
use App\Enums\OnlinePaymentStatus;
use App\Models\CbtResult;
use App\Models\CbtResultAccess;
use App\Models\OnlinePayment;
use App\Models\SchoolSetting;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Payments\OnlinePaymentService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8B: one Paystack payment unlocks one specific CBT result for that student.
 */
class CbtResultCheckerService
{
    public function __construct(private readonly OnlinePaymentService $payments) {}

    public function detailsRequirePayment(): bool
    {
        $settings = SchoolSetting::query()->first();

        return (bool) ($settings?->cbt_result_details_require_payment ?? true);
    }

    public function amountKobo(): int
    {
        return max(100, (int) config('services.cbt_result_checker.amount_kobo', 50000));
    }

    public function currency(): string
    {
        return strtoupper((string) config(
            'services.cbt_result_checker.currency',
            config('services.paystack.currency', 'NGN'),
        ));
    }

    public function amountLabel(): string
    {
        return Money::formatNaira($this->amountKobo());
    }

    public function pricing(): array
    {
        return [
            'amount_kobo' => $this->amountKobo(),
            'amount_label' => $this->amountLabel(),
            'currency' => $this->currency(),
            'details_require_payment' => $this->detailsRequirePayment(),
        ];
    }

    public function activeAccessFor(CbtResult $result, StudentProfile $student): ?CbtResultAccess
    {
        return CbtResultAccess::query()
            ->where('cbt_result_id', $result->id)
            ->where('student_profile_id', $student->id)
            ->whereNull('revoked_at')
            ->with('onlinePayment')
            ->first();
    }

    public function accountHasPaidAccess(CbtResult $result, StudentProfile $student): bool
    {
        if (! $this->detailsRequirePayment()) {
            return true;
        }

        return $this->activeAccessFor($result, $student) !== null;
    }

    /**
     * @return array{
     *     unlocked: bool,
     *     payment: ?OnlinePayment,
     *     authorization_url: ?string,
     *     access: ?CbtResultAccess
     * }
     */
    public function beginUnlock(CbtResult $result, User $user, StudentProfile $student): array
    {
        $this->assertOwnsResult($result, $user, $student);
        $this->assertResultFinalized($result);

        if ($this->accountHasPaidAccess($result, $student)) {
            return [
                'unlocked' => true,
                'payment' => $this->activeAccessFor($result, $student)?->onlinePayment,
                'authorization_url' => null,
                'access' => $this->activeAccessFor($result, $student),
            ];
        }

        // INVARIANT: a settled payment for this student+result must never start another charge.
        $settled = $this->matchingSettledPayment($result, $user, $student);
        if ($settled !== null) {
            $access = $this->activateFromPayment($settled);
            if ($access !== null) {
                return [
                    'unlocked' => true,
                    'payment' => $settled->fresh() ?? $settled,
                    'authorization_url' => null,
                    'access' => $access,
                ];
            }
        }

        $pending = OnlinePayment::query()
            ->where('purpose', OnlinePaymentPurpose::CbtResultChecker)
            ->where('user_id', $user->id)
            ->where('student_profile_id', $student->id)
            ->where('status', OnlinePaymentStatus::Pending)
            ->where('metadata->cbt_result_id', $result->id)
            ->whereNotNull('authorization_url')
            ->latest('id')
            ->first();

        if ($pending !== null) {
            return [
                'unlocked' => false,
                'payment' => $pending,
                'authorization_url' => (string) $pending->authorization_url,
                'access' => null,
            ];
        }

        $email = $this->resolveCheckoutEmail($user, $student);

        $payment = $this->payments->initiatePaystack([
            'email' => $email,
            'amount_kobo' => $this->amountKobo(),
            'currency' => $this->currency(),
            'purpose' => OnlinePaymentPurpose::CbtResultChecker,
            'callback_url' => route('payments.paystack.callback'),
            'metadata' => [
                'cbt_result_id' => $result->id,
                'cbt_attempt_id' => $result->attempt_id,
                'student_profile_id' => $student->id,
                'source' => 'cbt_result_checker',
            ],
        ], $user, $student, $result);

        return [
            'unlocked' => false,
            'payment' => $payment,
            'authorization_url' => (string) $payment->authorization_url,
            'access' => null,
        ];
    }

    /**
     * Settled Result Checker payment bound to this authenticated student + result.
     */
    public function matchingSettledPayment(CbtResult $result, User $user, StudentProfile $student): ?OnlinePayment
    {
        return OnlinePayment::query()
            ->where('purpose', OnlinePaymentPurpose::CbtResultChecker)
            ->where('status', OnlinePaymentStatus::Paid)
            ->where('user_id', $user->id)
            ->where('student_profile_id', $student->id)
            ->where('metadata->cbt_result_id', $result->id)
            ->latest('id')
            ->first();
    }

    public function activateFromPayment(OnlinePayment $payment): ?CbtResultAccess
    {
        if (! $payment->isPaid()) {
            return null;
        }

        if ($payment->purpose !== OnlinePaymentPurpose::CbtResultChecker) {
            return null;
        }

        $existingByPayment = CbtResultAccess::query()
            ->where('online_payment_id', $payment->id)
            ->first();
        if ($existingByPayment !== null) {
            return $existingByPayment;
        }

        $resultId = (int) ($payment->metadata['cbt_result_id'] ?? 0);
        $studentId = (int) ($payment->student_profile_id ?? ($payment->metadata['student_profile_id'] ?? 0));

        if ($resultId < 1 || $studentId < 1) {
            Log::warning('cbt_result_checker.missing_metadata', [
                'reference' => $payment->reference,
            ]);

            return null;
        }

        if ((int) $payment->amount_kobo < 100
            || strtoupper((string) $payment->currency) !== $this->currency()) {
            Log::warning('cbt_result_checker.invalid_settled_payment', [
                'reference' => $payment->reference,
                'amount_kobo' => $payment->amount_kobo,
                'currency' => $payment->currency,
            ]);

            return null;
        }

        $result = CbtResult::query()->with('attempt')->find($resultId);
        if ($result === null || (int) $result->attempt?->student_profile_id !== $studentId) {
            Log::warning('cbt_result_checker.ownership_mismatch', [
                'reference' => $payment->reference,
                'cbt_result_id' => $resultId,
            ]);

            return null;
        }

        try {
            return DB::transaction(function () use ($payment, $result, $studentId) {
                $byPayment = CbtResultAccess::query()
                    ->where('online_payment_id', $payment->id)
                    ->lockForUpdate()
                    ->first();
                if ($byPayment !== null) {
                    return $byPayment;
                }

                $byResult = CbtResultAccess::query()
                    ->where('cbt_result_id', $result->id)
                    ->where('student_profile_id', $studentId)
                    ->lockForUpdate()
                    ->first();

                if ($byResult !== null) {
                    if ($byResult->revoked_at !== null) {
                        $byResult->update([
                            'online_payment_id' => $payment->id,
                            'granted_at' => $payment->paid_at ?? now(),
                            'revoked_at' => null,
                        ]);
                    }

                    return $byResult->fresh() ?? $byResult;
                }

                return CbtResultAccess::query()->create([
                    'cbt_result_id' => $result->id,
                    'student_profile_id' => $studentId,
                    'online_payment_id' => $payment->id,
                    'granted_at' => $payment->paid_at ?? now(),
                    'revoked_at' => null,
                ]);
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return CbtResultAccess::query()
                ->where('online_payment_id', $payment->id)
                ->orWhere(function ($q) use ($result, $studentId) {
                    $q->where('cbt_result_id', $result->id)
                        ->where('student_profile_id', $studentId);
                })
                ->first();
        }
    }

    public function assertOwnsResult(CbtResult $result, User $user, StudentProfile $student): void
    {
        $result->loadMissing('attempt');

        if ((int) $result->attempt?->user_id !== (int) $user->id
            || (int) $result->attempt?->student_profile_id !== (int) $student->id) {
            abort(404);
        }
    }

    public function assertResultFinalized(CbtResult $result): void
    {
        $result->loadMissing('attempt');

        if ($result->attempt === null
            || $result->attempt->status !== CbtAttemptStatus::Submitted
            || $result->marked_at === null) {
            throw ValidationException::withMessages([
                'result' => 'This CBT result is not ready for Result Checker purchase.',
            ]);
        }
    }

    private function resolveCheckoutEmail(User $user, StudentProfile $student): string
    {
        $candidates = array_filter([
            $user->email,
            $student->email ?? null,
        ]);

        foreach ($candidates as $candidate) {
            $email = strtolower(trim((string) $candidate));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && ! str_ends_with($email, '.invalid')) {
                return $email;
            }
        }

        throw ValidationException::withMessages([
            'email' => 'A valid account email is required for Result Checker checkout.',
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? $e->getCode());

        return $code === '1062' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
