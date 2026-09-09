<?php

namespace App\Services\Payments\Paystack;

use App\Enums\PaymentProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Thin HTTP client for official Paystack Transaction API.
 *
 * @see https://paystack.com/docs/api/transaction/
 * @see https://paystack.com/docs/payments/verify-payments/
 * @see https://paystack.com/docs/payments/webhooks/
 */
class PaystackClient
{
    public function enabled(): bool
    {
        return filled(config('services.paystack.secret_key'));
    }

    public function publicKey(): ?string
    {
        $key = config('services.paystack.public_key');

        return filled($key) ? (string) $key : null;
    }

    public function currency(): string
    {
        return strtoupper((string) config('services.paystack.currency', 'NGN'));
    }

    /**
     * POST /transaction/initialize — amount must be in subunits (kobo for NGN).
     *
     * @param  array{email: string, amount_kobo: int, reference: string, callback_url: string, currency?: string, metadata?: array<string, mixed>}  $payload
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initialize(array $payload): array
    {
        $this->assertConfigured();

        try {
            $response = Http::withToken((string) config('services.paystack.secret_key'))
                ->acceptJson()
                ->asJson()
                ->timeout(30)
                ->connectTimeout(10)
                ->post($this->baseUrl().'/transaction/initialize', [
                    'email' => $payload['email'],
                    'amount' => (int) $payload['amount_kobo'],
                    'reference' => $payload['reference'],
                    'callback_url' => $payload['callback_url'],
                    'currency' => strtoupper((string) ($payload['currency'] ?? $this->currency())),
                    'metadata' => $payload['metadata'] ?? [],
                ]);
        } catch (ConnectionException $e) {
            report($e);

            throw ValidationException::withMessages([
                'payment' => 'Unable to initialize payment. Please try again.',
            ]);
        }

        if ($response->failed()) {
            throw ValidationException::withMessages([
                'payment' => $response->json('message') ?: 'Unable to initialize payment. Please try again.',
            ]);
        }

        $data = $response->json('data') ?? [];

        return [
            'authorization_url' => (string) ($data['authorization_url'] ?? ''),
            'access_code' => (string) ($data['access_code'] ?? ''),
            'reference' => (string) ($data['reference'] ?? $payload['reference']),
        ];
    }

    /**
     * GET /transaction/verify/:reference
     *
     * @return array<string, mixed>
     */
    public function verify(string $reference): array
    {
        $this->assertConfigured();

        try {
            $response = Http::withToken((string) config('services.paystack.secret_key'))
                ->acceptJson()
                ->timeout(30)
                ->connectTimeout(10)
                ->get($this->baseUrl().'/transaction/verify/'.rawurlencode($reference))
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            report($e);

            throw ValidationException::withMessages([
                'payment' => 'Unable to verify payment. Please try again.',
            ]);
        }

        $body = $response->json();
        if (! ($body['status'] ?? false)) {
            throw ValidationException::withMessages([
                'payment' => (string) ($body['message'] ?? 'Payment verification failed.'),
            ]);
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * Paystack signs webhooks with HMAC SHA512 of the raw body using the secret key.
     * Header: x-paystack-signature
     */
    public function signatureIsValid(string $rawBody, ?string $signature): bool
    {
        if (! filled($signature) || ! $this->enabled()) {
            return false;
        }

        $computed = hash_hmac('sha512', $rawBody, (string) config('services.paystack.secret_key'));

        return hash_equals($computed, $signature);
    }

    private function assertConfigured(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Paystack is not configured. Set PAYSTACK_SECRET_KEY.');
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.paystack.base_url', 'https://api.paystack.co'), '/');
    }

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paystack;
    }
}
