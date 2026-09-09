<?php

namespace Tests\Feature\Payments;

use App\Enums\OnlinePaymentPurpose;
use App\Enums\OnlinePaymentStatus;
use App\Enums\RoleSlug;
use App\Models\OnlinePayment;
use App\Models\PaystackWebhookEvent;
use App\Models\User;
use App\Services\Payments\Paystack\PaystackPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PaystackOnlinePaymentTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_configuration_loads_and_missing_secret_fails_safely(): void
    {
        $this->assertSame('NGN', config('services.paystack.currency'));
        $this->assertTrue((bool) config('services.paystack.test_payments_enabled'));

        config(['services.paystack.secret_key' => null]);

        $this->actingAs($this->userWithRole(RoleSlug::Student))
            ->postJson('/api/v1/payments/test/initialize')
            ->assertStatus(422)
            ->assertJsonMissingPath('data.authorization_url');
    }

    public function test_guest_cannot_initialize_and_authenticated_user_can(): void
    {
        $this->fakeInitialize();

        $this->postJson('/api/v1/payments/test/initialize', ['amount' => 100])
            ->assertUnauthorized();

        $user = $this->userWithRole(RoleSlug::Student, ['email' => 'payer@example.test']);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/payments/test/initialize', [
                'amount' => 100,
                'amount_kobo' => 100,
            ])
            ->assertOk();

        $this->assertSame(10000, $response->json('data.amount_kobo'));
        $this->assertStringStartsWith('SRS-PAY-', $response->json('data.reference'));
        $this->assertStringContainsString('checkout.paystack.com', $response->json('data.authorization_url'));
        $this->assertStringNotContainsString('sk_test', json_encode($response->json()));
        $this->assertDatabaseHas('online_payments', [
            'reference' => $response->json('data.reference'),
            'amount_kobo' => 10000,
            'status' => OnlinePaymentStatus::Pending->value,
            'purpose' => OnlinePaymentPurpose::TestPayment->value,
            'user_id' => $user->id,
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/transaction/initialize')
                && (int) $request['amount'] === 10000
                && $request['currency'] === 'NGN';
        });
    }

    public function test_gateway_init_failure_marks_failed_not_paid(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => false,
                'message' => 'Gateway down',
            ], 500),
        ]);

        $user = $this->userWithRole(RoleSlug::Student, ['email' => 'payer@example.test']);

        $this->actingAs($user)
            ->postJson('/api/v1/payments/test/initialize')
            ->assertStatus(422);

        $this->assertDatabaseHas('online_payments', [
            'user_id' => $user->id,
            'status' => OnlinePaymentStatus::Failed->value,
        ]);
        $this->assertSame(0, OnlinePayment::query()->where('status', OnlinePaymentStatus::Paid)->count());
    }

    public function test_callback_fake_success_query_does_not_mark_paid_without_verify(): void
    {
        $payment = $this->pendingPayment();

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'failed',
                    'amount' => 10000,
                    'currency' => 'NGN',
                    'reference' => $payment->reference,
                    'gateway_response' => 'Declined',
                ],
            ], 200),
        ]);

        $this->get('/payments/paystack/callback?reference='.$payment->reference.'&status=success')
            ->assertRedirect();

        $this->assertSame(OnlinePaymentStatus::Failed, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);
    }

    public function test_verification_success_amount_mismatch_and_idempotency(): void
    {
        $payment = $this->pendingPayment(['amount_kobo' => 10000]);
        $this->fakeVerify($payment->reference, 10000, 'success');

        $first = app(PaystackPaymentService::class)->verifyAndSettle($payment->reference);
        $this->assertTrue($first['newly_paid']);
        $paidAt = $first['payment']->paid_at;
        $this->assertNotNull($paidAt);

        $second = app(PaystackPaymentService::class)->verifyAndSettle($payment->reference);
        $this->assertFalse($second['newly_paid']);
        $this->assertTrue($paidAt->equalTo($second['payment']->paid_at));

        $mismatch = $this->pendingPayment(['amount_kobo' => 100000, 'reference' => 'SRS-PAY-MISMATCH1']);
        $this->fakeVerify($mismatch->reference, 50000, 'success');

        $rejected = app(PaystackPaymentService::class)->verifyAndSettle($mismatch->reference);
        $this->assertFalse($rejected['newly_paid']);
        $this->assertSame(OnlinePaymentStatus::Failed, $rejected['payment']->status);
        $this->assertSame('Paystack amount/currency mismatch.', $rejected['payment']->failure_reason);
    }

    public function test_webhook_signature_and_duplicate_idempotency(): void
    {
        $payment = $this->pendingPayment();
        $this->fakeVerify($payment->reference, 10000, 'success');

        $payload = json_encode([
            'event' => 'charge.success',
            'id' => 'evt_phase8a_1',
            'data' => [
                'id' => 4242,
                'reference' => $payment->reference,
                'status' => 'success',
                'amount' => 10000,
                'currency' => 'NGN',
            ],
        ], JSON_THROW_ON_ERROR);

        $bad = $this->call('POST', '/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        $this->assertSame(400, $bad->getStatusCode());

        $sig = hash_hmac('sha512', $payload, (string) config('services.paystack.secret_key'));

        $this->call('POST', '/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-PAYSTACK-SIGNATURE' => $sig,
        ], $payload)->assertOk();

        $this->call('POST', '/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-PAYSTACK-SIGNATURE' => $sig,
        ], $payload)->assertOk();

        $this->assertSame(1, PaystackWebhookEvent::query()->where('event_id', 'evt_phase8a_1')->count());
        $this->assertSame(1, OnlinePayment::query()->where('reference', $payment->reference)->where('status', OnlinePaymentStatus::Paid)->count());
        $this->assertSame(OnlinePaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_webhook_retry_recovers_after_transient_verify_failure(): void
    {
        $payment = $this->pendingPayment();

        // Prior delivery recorded the unique event without completing settlement.
        PaystackWebhookEvent::query()->create([
            'event_id' => 'evt_retry_stuck_1',
            'event' => 'charge.success',
            'reference' => $payment->reference,
            'status' => 'received',
            'payload' => ['event' => 'charge.success', 'reference' => $payment->reference],
            'processed_at' => null,
        ]);

        $payload = json_encode([
            'event' => 'charge.success',
            'id' => 'evt_retry_stuck_1',
            'data' => [
                'id' => 5555,
                'reference' => $payment->reference,
                'status' => 'success',
                'amount' => 10000,
                'currency' => 'NGN',
            ],
        ], JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha512', $payload, (string) config('services.paystack.secret_key'));

        $this->fakeVerify($payment->reference, 10000, 'success');
        $this->call('POST', '/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-PAYSTACK-SIGNATURE' => $sig,
        ], $payload)->assertOk();

        $this->assertSame(OnlinePaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(1, PaystackWebhookEvent::query()->where('event_id', 'evt_retry_stuck_1')->count());
        $this->assertNotNull(PaystackWebhookEvent::query()->where('event_id', 'evt_retry_stuck_1')->value('processed_at'));
    }

    public function test_webhook_currency_mismatch_does_not_mark_paid(): void
    {
        $payment = $this->pendingPayment(['currency' => 'NGN']);
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'amount' => 10000,
                    'currency' => 'USD',
                    'reference' => $payment->reference,
                    'channel' => 'card',
                    'gateway_response' => 'Successful',
                    'paid_at' => now()->toIso8601String(),
                ],
            ], 200),
        ]);

        $payload = json_encode([
            'event' => 'charge.success',
            'id' => 'evt_currency_mismatch',
            'data' => [
                'id' => 77,
                'reference' => $payment->reference,
                'status' => 'success',
                'amount' => 10000,
                'currency' => 'USD',
            ],
        ], JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha512', $payload, (string) config('services.paystack.secret_key'));

        $this->call('POST', '/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-PAYSTACK-SIGNATURE' => $sig,
        ], $payload)->assertOk();

        $this->assertSame(OnlinePaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame('Paystack amount/currency mismatch.', $payment->fresh()->failure_reason);
    }

    public function test_student_cannot_view_another_transaction_or_mutate_status(): void
    {
        $owner = $this->userWithRole(RoleSlug::Student, ['email' => 'owner@example.test']);
        $other = $this->userWithRole(RoleSlug::Student, ['email' => 'other@example.test']);
        $payment = $this->pendingPayment(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson('/api/v1/payments/transactions/'.$payment->reference)
            ->assertForbidden();

        $this->actingAs($other)
            ->postJson('/api/v1/payments/transactions/'.$payment->reference.'/verify')
            ->assertForbidden();

        $this->actingAs($owner)
            ->putJson('/api/v1/payments/transactions/'.$payment->reference, [
                'status' => 'paid',
                'amount_kobo' => 1,
            ])
            ->assertStatus(405);

        $this->assertSame(OnlinePaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_admin_can_list_transactions(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);
        $this->pendingPayment();

        $this->actingAs($admin)
            ->getJson('/api/v1/payments/admin/transactions')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    private function pendingPayment(array $overrides = []): OnlinePayment
    {
        $user = isset($overrides['user_id'])
            ? User::query()->findOrFail($overrides['user_id'])
            : $this->userWithRole(RoleSlug::Student, [
                'email' => 'pending-'.Str::lower(Str::random(8)).'@example.test',
            ]);

        return OnlinePayment::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'reference' => 'SRS-PAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
            'provider' => 'paystack',
            'purpose' => OnlinePaymentPurpose::TestPayment,
            'user_id' => $user->id,
            'email' => $user->email,
            'amount_kobo' => 10000,
            'currency' => 'NGN',
            'status' => OnlinePaymentStatus::Pending,
            'metadata' => ['source' => 'test'],
        ], $overrides));
    }

    private function fakeInitialize(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/test',
                    'access_code' => 'ACCESS',
                    'reference' => 'ignored',
                ],
            ], 200),
        ]);
    }

    private function fakeVerify(string $reference, int $amountKobo, string $status): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => $status,
                    'amount' => $amountKobo,
                    'currency' => 'NGN',
                    'reference' => $reference,
                    'channel' => 'card',
                    'gateway_response' => $status === 'success' ? 'Successful' : 'Declined',
                    'paid_at' => now()->toIso8601String(),
                ],
            ], 200),
        ]);
    }
}
