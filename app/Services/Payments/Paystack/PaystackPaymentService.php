<?php

namespace App\Services\Payments\Paystack;

use App\Enums\OnlinePaymentPurpose;
use App\Enums\OnlinePaymentStatus;
use App\Enums\PaymentProvider;
use App\Models\OnlinePayment;
use App\Models\PaystackWebhookEvent;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaystackPaymentService
{
    public function __construct(private readonly PaystackClient $paystack) {}

    /**
     * Create a local pending transaction first, then initialize Paystack checkout.
     *
     * @param  array{email: string, amount_kobo: int, currency?: string, purpose: OnlinePaymentPurpose|string, metadata?: array<string, mixed>, callback_url?: string}  $attributes
     */
    public function initialize(
        array $attributes,
        ?User $user = null,
        ?StudentProfile $student = null,
        ?Model $payable = null,
    ): OnlinePayment {
        if (! $this->paystack->enabled()) {
            throw ValidationException::withMessages([
                'payment' => 'Online card checkout is not configured yet.',
            ]);
        }

        $amount = (int) $attributes['amount_kobo'];
        if ($amount < 100) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount must be at least ₦1.00.',
            ]);
        }

        $purpose = $attributes['purpose'] instanceof OnlinePaymentPurpose
            ? $attributes['purpose']
            : OnlinePaymentPurpose::from((string) $attributes['purpose']);

        $currency = strtoupper((string) ($attributes['currency'] ?? $this->paystack->currency()));
        $callbackUrl = (string) ($attributes['callback_url']
            ?? config('services.paystack.callback_url')
            ?: route('payments.paystack.callback'));

        $reference = $this->uniqueReference('SRS-PAY');

        $payment = OnlinePayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'reference' => $reference,
            'provider' => PaymentProvider::Paystack,
            'purpose' => $purpose,
            'payable_type' => $payable?->getMorphClass(),
            'payable_id' => $payable?->getKey(),
            'user_id' => $user?->id,
            'student_profile_id' => $student?->id,
            'email' => strtolower(trim((string) $attributes['email'])),
            'amount_kobo' => $amount,
            'currency' => $currency,
            'status' => OnlinePaymentStatus::Pending,
            'metadata' => $attributes['metadata'] ?? [],
        ]);

        try {
            $init = $this->paystack->initialize([
                'email' => $payment->email,
                'amount_kobo' => $payment->amount_kobo,
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'callback_url' => $callbackUrl,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'online_payment_uuid' => $payment->uuid,
                    'purpose' => $purpose->value,
                ]),
            ]);
        } catch (\Throwable $e) {
            $payment->update([
                'status' => OnlinePaymentStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'Paystack initialization failed.',
            ]);

            Log::warning('paystack.initialize_failed', [
                'reference' => $payment->reference,
                'provider' => PaymentProvider::Paystack->value,
            ]);

            if ($e instanceof ValidationException) {
                throw $e;
            }

            report($e);

            throw ValidationException::withMessages([
                'payment' => 'Unable to initialize payment. Please try again.',
            ]);
        }

        $payment->update([
            'authorization_url' => $init['authorization_url'],
            'access_code' => $init['access_code'] ?: null,
            'provider_reference' => $init['reference'] ?: $payment->reference,
        ]);

        return $payment->fresh() ?? $payment;
    }

    /**
     * @deprecated Use initialize()
     *
     * @param  array{email: string, amount_kobo: int, currency?: string, purpose: OnlinePaymentPurpose|string, metadata?: array<string, mixed>, callback_url: string}  $attributes
     */
    public function initiatePaystack(
        array $attributes,
        ?User $user = null,
        ?StudentProfile $student = null,
        ?Model $payable = null,
    ): OnlinePayment {
        return $this->initialize($attributes, $user, $student, $payable);
    }

    /**
     * Server-authoritative settlement. Idempotent for already-paid rows.
     *
     * @return array{payment: OnlinePayment, newly_paid: bool}
     */
    public function verifyAndSettle(string $reference): array
    {
        $payment = $this->findByReference($reference);

        if ($payment === null) {
            throw ValidationException::withMessages([
                'reference' => 'Payment reference was not found.',
            ]);
        }

        if ($payment->isPaid()) {
            return ['payment' => $payment, 'newly_paid' => false];
        }

        if ($payment->status === OnlinePaymentStatus::Cancelled) {
            throw ValidationException::withMessages([
                'payment' => 'This payment was cancelled.',
            ]);
        }

        $verified = $this->paystack->verify($payment->reference);

        return $this->applyPaystackVerification($payment, $verified);
    }

    /**
     * @param  array<string, mixed>  $verified
     * @return array{payment: OnlinePayment, newly_paid: bool}
     */
    public function applyPaystackVerification(OnlinePayment $payment, array $verified): array
    {
        return DB::transaction(function () use ($payment, $verified) {
            /** @var OnlinePayment $locked */
            $locked = OnlinePayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->isPaid()) {
                return ['payment' => $locked->fresh() ?? $locked, 'newly_paid' => false];
            }

            if ($locked->status === OnlinePaymentStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'payment' => 'This payment was cancelled.',
                ]);
            }

            $status = strtolower((string) ($verified['status'] ?? ''));
            $amount = (int) ($verified['amount'] ?? 0);
            $currency = strtoupper((string) ($verified['currency'] ?? 'NGN'));
            $providerRef = (string) ($verified['reference'] ?? $locked->reference);

            $safePayload = [
                'status' => $verified['status'] ?? null,
                'amount' => $verified['amount'] ?? null,
                'currency' => $verified['currency'] ?? null,
                'reference' => $verified['reference'] ?? null,
                'paid_at' => $verified['paid_at'] ?? null,
                'channel' => $verified['channel'] ?? null,
                'gateway_response' => $verified['gateway_response'] ?? null,
            ];

            if ($status !== 'success') {
                $locked->update([
                    'status' => OnlinePaymentStatus::Failed,
                    'failed_at' => now(),
                    'failure_reason' => (string) ($verified['gateway_response'] ?? 'Payment was not successful.'),
                    'provider_reference' => $providerRef,
                    'channel' => $verified['channel'] ?? $locked->channel,
                    'gateway_status' => (string) ($verified['status'] ?? 'failed'),
                    'provider_payload' => $safePayload,
                    'verified_at' => now(),
                ]);

                return ['payment' => $locked->fresh() ?? $locked, 'newly_paid' => false];
            }

            if ($amount !== (int) $locked->amount_kobo || $currency !== strtoupper((string) $locked->currency)) {
                $locked->update([
                    'status' => OnlinePaymentStatus::Failed,
                    'failed_at' => now(),
                    'failure_reason' => 'Paystack amount/currency mismatch.',
                    'provider_reference' => $providerRef,
                    'channel' => $verified['channel'] ?? $locked->channel,
                    'gateway_status' => (string) ($verified['status'] ?? 'success'),
                    'provider_payload' => $safePayload,
                    'verified_at' => now(),
                ]);

                Log::warning('paystack.amount_mismatch', [
                    'reference' => $locked->reference,
                    'expected_kobo' => $locked->amount_kobo,
                    'gateway_kobo' => $amount,
                    'expected_currency' => $locked->currency,
                    'gateway_currency' => $currency,
                ]);

                // Do not throw inside the transaction — that would roll back the audit failure state.
                return ['payment' => $locked->fresh() ?? $locked, 'newly_paid' => false];
            }

            $locked->update([
                'status' => OnlinePaymentStatus::Paid,
                'paid_at' => $locked->paid_at ?? now(),
                'verified_at' => $locked->verified_at ?? now(),
                'provider_reference' => $providerRef,
                'channel' => $verified['channel'] ?? $locked->channel,
                'gateway_status' => 'success',
                'provider_payload' => $safePayload,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            return ['payment' => $locked->fresh() ?? $locked, 'newly_paid' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payment: ?OnlinePayment, newly_paid: bool, duplicate_event: bool}
     */
    public function handlePaystackWebhook(string $rawBody, ?string $signature, array $payload): array
    {
        if (! $this->paystack->signatureIsValid($rawBody, $signature)) {
            throw ValidationException::withMessages([
                'webhook' => 'Invalid Paystack signature.',
            ]);
        }

        $event = (string) ($payload['event'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = (string) ($data['reference'] ?? '');
        $eventId = $this->resolveEventId($payload, $data, $event, $reference);

        if ($eventId === '') {
            throw ValidationException::withMessages([
                'webhook' => 'Webhook event id missing.',
            ]);
        }

        try {
            $record = PaystackWebhookEvent::query()->create([
                'event_id' => $eventId,
                'event' => $event,
                'reference' => $reference !== '' ? $reference : null,
                'status' => 'received',
                'payload' => [
                    'event' => $event,
                    'reference' => $reference,
                    'status' => $data['status'] ?? null,
                    'amount' => $data['amount'] ?? null,
                ],
                'processed_at' => null,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $payment = $reference !== '' ? $this->findByReference($reference) : null;

            return [
                'payment' => $payment,
                'newly_paid' => false,
                'duplicate_event' => true,
            ];
        }

        if ($event !== 'charge.success' || $reference === '') {
            $record->update([
                'status' => 'ignored',
                'processed_at' => now(),
            ]);

            return ['payment' => null, 'newly_paid' => false, 'duplicate_event' => false];
        }

        $result = $this->verifyAndSettle($reference);

        $record->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        Log::info('paystack.webhook_processed', [
            'event' => $event,
            'reference' => $reference,
            'newly_paid' => $result['newly_paid'],
        ]);

        return [
            'payment' => $result['payment'],
            'newly_paid' => $result['newly_paid'],
            'duplicate_event' => false,
        ];
    }

    public function markCancelled(OnlinePayment $payment, ?string $reason = null): OnlinePayment
    {
        if ($payment->isPaid()) {
            throw ValidationException::withMessages([
                'payment' => 'Paid transactions cannot be cancelled.',
            ]);
        }

        $payment->update([
            'status' => OnlinePaymentStatus::Cancelled,
            'failure_reason' => $reason ?? 'Cancelled.',
            'failed_at' => now(),
        ]);

        return $payment->fresh() ?? $payment;
    }

    public function markRefunded(OnlinePayment $payment, ?string $reason = null): OnlinePayment
    {
        $payment->update([
            'status' => OnlinePaymentStatus::Refunded,
            'failure_reason' => $reason,
        ]);

        return $payment->fresh() ?? $payment;
    }

    public function findByReference(string $reference): ?OnlinePayment
    {
        return OnlinePayment::query()
            ->where(function ($q) use ($reference) {
                $q->where('reference', $reference)
                    ->orWhere('provider_reference', $reference);
            })
            ->first();
    }

    public function findByUuid(string $uuid): ?OnlinePayment
    {
        return OnlinePayment::query()->where('uuid', $uuid)->first();
    }

    /**
     * Controlled Phase 8A test amount (kobo). Never trust the browser.
     */
    public function testAmountKobo(): int
    {
        return max(100, (int) config('services.paystack.test_amount_kobo', 10000));
    }

    public function testPaymentsEnabled(): bool
    {
        return (bool) config('services.paystack.test_payments_enabled', false)
            || app()->environment(['local', 'testing']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    private function resolveEventId(array $payload, array $data, string $event, string $reference): string
    {
        $eventId = (string) ($payload['id'] ?? '');
        if ($eventId !== '') {
            return $eventId;
        }

        $dataId = (string) ($data['id'] ?? '');
        if ($dataId !== '') {
            return $event.'|'.$dataId;
        }

        if ($reference !== '') {
            return $event.'|'.$reference.'|'.(string) ($data['paid_at'] ?? $data['created_at'] ?? '');
        }

        return '';
    }

    private function uniqueReference(string $prefix): string
    {
        do {
            $reference = strtoupper($prefix).'-'.now('Africa/Lagos')->format('Ymd').'-'.Str::upper(Str::random(10));
        } while (OnlinePayment::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? $e->getCode());

        return $code === '1062' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
