<?php

namespace App\Services\Payments;

use App\Models\OnlinePayment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Payments\Paystack\PaystackPaymentService;
use Illuminate\Database\Eloquent\Model;

/**
 * Compatibility facade for callers that still type-hint OnlinePaymentService.
 * Prefer PaystackPaymentService for new code.
 */
class OnlinePaymentService
{
    public function __construct(private readonly PaystackPaymentService $paystackPayments) {}

    /**
     * @param  array{email: string, amount_kobo: int, currency?: string, purpose: mixed, metadata?: array<string, mixed>, callback_url?: string}  $attributes
     */
    public function initiatePaystack(
        array $attributes,
        ?User $user = null,
        ?StudentProfile $student = null,
        ?Model $payable = null,
    ): OnlinePayment {
        return $this->paystackPayments->initialize($attributes, $user, $student, $payable);
    }

    /**
     * @return array{payment: OnlinePayment, newly_paid: bool}
     */
    public function verifyAndSettle(string $reference): array
    {
        return $this->paystackPayments->verifyAndSettle($reference);
    }

    /**
     * @param  array<string, mixed>  $verified
     * @return array{payment: OnlinePayment, newly_paid: bool}
     */
    public function applyPaystackVerification(OnlinePayment $payment, array $verified): array
    {
        return $this->paystackPayments->applyPaystackVerification($payment, $verified);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payment: ?OnlinePayment, newly_paid: bool, duplicate_event: bool}
     */
    public function handlePaystackWebhook(string $rawBody, ?string $signature, array $payload): array
    {
        return $this->paystackPayments->handlePaystackWebhook($rawBody, $signature, $payload);
    }

    public function markRefunded(OnlinePayment $payment, ?string $reason = null): OnlinePayment
    {
        return $this->paystackPayments->markRefunded($payment, $reason);
    }
}
