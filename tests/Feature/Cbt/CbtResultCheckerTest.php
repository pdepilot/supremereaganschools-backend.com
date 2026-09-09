<?php

namespace Tests\Feature\Cbt;

use App\Enums\OnlinePaymentPurpose;
use App\Enums\OnlinePaymentStatus;
use App\Enums\RoleSlug;
use App\Events\OnlinePaymentSettled;
use App\Models\CbtResult;
use App\Models\CbtResultAccess;
use App\Models\OnlinePayment;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtResultCheckerService;
use App\Services\Cbt\CbtSubmissionService;
use App\Services\Payments\Paystack\PaystackPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesCbtContext;
use Tests\TestCase;

class CbtResultCheckerTest extends TestCase
{
    use CreatesAcademicContext;
    use CreatesCbtContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->assessmentCatalogue();
        $this->settings(['cbt_result_details_require_payment' => true]);
    }

    public function test_guest_cannot_unlock_and_student_cannot_unlock_foreign_result(): void
    {
        $mine = $this->submittedResult();
        $other = $this->submittedResult();

        $this->postJson('/api/v1/cbt/results/'.$mine['result']->id.'/unlock')
            ->assertUnauthorized();

        $this->actingAsCbt($mine['user'])
            ->postJson('/api/v1/cbt/results/'.$other['result']->id.'/unlock')
            ->assertForbidden();

        $this->actingAsCbt($other['user'])
            ->getJson('/api/v1/cbt/results/'.$mine['result']->id.'/detailed')
            ->assertForbidden();
    }

    public function test_locked_result_excludes_protected_fields_and_unlock_uses_server_amount(): void
    {
        $this->fakeInitialize();
        $ctx = $this->submittedResult();

        $list = $this->actingAsCbt($ctx['user'])->getJson('/api/v1/cbt/results')->assertOk();
        $row = collect($list->json('data.results'))->firstWhere('id', $ctx['result']->id);
        $this->assertFalse($row['details_unlocked']);
        $this->assertNull($row['score']);
        $this->assertNull($row['percentage']);
        $this->assertNull($row['grade']);
        $this->assertNull($row['passed']);
        $this->assertSame(50000, $list->json('data.pricing.amount_kobo'));

        $this->actingAsCbt($ctx['user'])
            ->getJson('/api/v1/cbt/results/'.$ctx['result']->id.'/detailed')
            ->assertStatus(402);

        $started = $this->actingAsCbt($ctx['user'])
            ->postJson('/api/v1/cbt/results/'.$ctx['result']->id.'/unlock', [
                'amount' => 100,
                'amount_kobo' => 100,
                'currency' => 'USD',
            ])
            ->assertOk();

        $this->assertFalse($started->json('data.unlocked'));
        $this->assertSame(50000, $started->json('data.amount_kobo'));
        $this->assertSame('NGN', $started->json('data.currency'));
        $this->assertStringContainsString('checkout.paystack.com', $started->json('data.authorization_url'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/transaction/initialize')
            && (int) $request['amount'] === 50000
            && $request['currency'] === 'NGN');

        $this->assertDatabaseHas('online_payments', [
            'reference' => $started->json('data.reference'),
            'purpose' => OnlinePaymentPurpose::CbtResultChecker->value,
            'amount_kobo' => 50000,
            'status' => OnlinePaymentStatus::Pending->value,
        ]);
    }

    public function test_pending_payment_is_reused_and_already_unlocked_skips_new_charge(): void
    {
        $this->fakeInitialize();
        $ctx = $this->submittedResult();

        $first = $this->actingAsCbt($ctx['user'])
            ->postJson('/api/v1/cbt/results/'.$ctx['result']->id.'/unlock')
            ->assertOk();
        $second = $this->actingAsCbt($ctx['user'])
            ->postJson('/api/v1/cbt/results/'.$ctx['result']->id.'/unlock')
            ->assertOk();

        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));
        $this->assertSame(1, OnlinePayment::query()
            ->where('purpose', OnlinePaymentPurpose::CbtResultChecker)
            ->where('metadata->cbt_result_id', $ctx['result']->id)
            ->count());

        $this->grantAccess($ctx);

        $again = $this->actingAsCbt($ctx['user'])
            ->postJson('/api/v1/cbt/results/'.$ctx['result']->id.'/unlock')
            ->assertOk();
        $this->assertTrue($again->json('data.unlocked'));
        $this->assertNull($again->json('data.authorization_url'));
    }

    public function test_fake_callback_success_does_not_unlock_without_gateway_success(): void
    {
        $this->fakeInitialize();
        $ctx = $this->submittedResult();
        $reference = $this->actingAsCbt($ctx['user'])
            ->postJson('/api/v1/cbt/results/'.$ctx['result']->id.'/unlock')
            ->json('data.reference');

        $this->fakeVerify($reference, 50000, 'failed');
        $this->get('/payments/paystack/callback?reference='.$reference.'&status=success')
            ->assertRedirect();

        $this->assertSame(0, CbtResultAccess::query()->count());
        $this->actingAsCbt($ctx['user'])
            ->getJson('/api/v1/cbt/results/'.$ctx['result']->id.'/detailed')
            ->assertStatus(402);
    }

    public function test_webhook_and_verify_grant_access_idempotently(): void
    {
        $this->fakeInitialize();
        $ctx = $this->submittedResult();
        $reference = $this->actingAsCbt($ctx['user'])
            ->postJson('/api/v1/cbt/results/'.$ctx['result']->id.'/unlock')
            ->json('data.reference');

        $this->fakeVerify($reference, 50000, 'success');
        $payload = json_encode([
            'event' => 'charge.success',
            'id' => 'evt_rc_1',
            'data' => [
                'id' => 77,
                'reference' => $reference,
                'status' => 'success',
                'amount' => 50000,
                'currency' => 'NGN',
            ],
        ], JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha512', $payload, (string) config('services.paystack.secret_key'));

        $this->call('POST', '/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-PAYSTACK-SIGNATURE' => $sig,
        ], $payload)->assertOk();

        $this->call('POST', '/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-PAYSTACK-SIGNATURE' => $sig,
        ], $payload)->assertOk();

        $this->assertSame(1, CbtResultAccess::query()->count());
        $this->assertSame(1, OnlinePayment::query()->where('reference', $reference)->where('status', OnlinePaymentStatus::Paid)->count());

        $detailed = $this->actingAsCbt($ctx['user'])
            ->getJson('/api/v1/cbt/results/'.$ctx['result']->id.'/detailed')
            ->assertOk()
            ->json('data.result');

        $this->assertSame('100.00', $detailed['percentage']);
        $this->assertSame((string) $ctx['result']->score, $detailed['score']);
        $this->assertNotNull($detailed['grade']);
        $this->assertTrue($detailed['passed']);
        $this->assertSame($ctx['result']->id, $detailed['id']);
    }

    public function test_amount_mismatch_does_not_unlock(): void
    {
        $this->fakeInitialize();
        $ctx = $this->submittedResult();
        $reference = $this->actingAsCbt($ctx['user'])
            ->postJson('/api/v1/cbt/results/'.$ctx['result']->id.'/unlock')
            ->json('data.reference');

        $this->fakeVerify($reference, 100, 'success');
        app(PaystackPaymentService::class)->verifyAndSettle($reference);

        $this->assertSame(OnlinePaymentStatus::Failed, OnlinePayment::query()->where('reference', $reference)->first()->status);
        $this->assertSame(0, CbtResultAccess::query()->count());
    }

    public function test_concurrent_settlement_creates_one_access_row(): void
    {
        $ctx = $this->submittedResult();
        $payment = OnlinePayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'SRS-PAY-CONCURRENT1',
            'provider' => 'paystack',
            'purpose' => OnlinePaymentPurpose::CbtResultChecker,
            'user_id' => $ctx['user']->id,
            'student_profile_id' => $ctx['student']->id,
            'email' => $ctx['user']->email,
            'amount_kobo' => 50000,
            'currency' => 'NGN',
            'status' => OnlinePaymentStatus::Paid,
            'paid_at' => now(),
            'verified_at' => now(),
            'metadata' => [
                'cbt_result_id' => $ctx['result']->id,
                'cbt_attempt_id' => $ctx['result']->attempt_id,
                'student_profile_id' => $ctx['student']->id,
            ],
        ]);

        $service = app(CbtResultCheckerService::class);
        $a = $service->activateFromPayment($payment);
        $b = $service->activateFromPayment($payment);

        $this->assertNotNull($a);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, CbtResultAccess::query()->count());
    }

    public function test_admin_can_view_results_with_checker_status_without_payment(): void
    {
        $ctx = $this->submittedResult();
        $admin = $this->userWithRole(RoleSlug::ExaminationOfficer);

        $this->actingAsCbt($admin)
            ->getJson('/api/v1/cbt/admin/results')
            ->assertOk()
            ->assertJsonPath('data.items.0.result_checker_unlocked', false)
            ->assertJsonPath('data.items.0.percentage', (string) $ctx['result']->percentage);
    }

    public function test_settled_event_listener_grants_access(): void
    {
        $ctx = $this->submittedResult();
        $payment = OnlinePayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'SRS-PAY-EVENT1',
            'provider' => 'paystack',
            'purpose' => OnlinePaymentPurpose::CbtResultChecker,
            'user_id' => $ctx['user']->id,
            'student_profile_id' => $ctx['student']->id,
            'email' => $ctx['user']->email,
            'amount_kobo' => 50000,
            'currency' => 'NGN',
            'status' => OnlinePaymentStatus::Paid,
            'paid_at' => now(),
            'metadata' => [
                'cbt_result_id' => $ctx['result']->id,
                'student_profile_id' => $ctx['student']->id,
            ],
        ]);

        event(new OnlinePaymentSettled($payment, true));

        $this->assertSame(1, CbtResultAccess::query()
            ->where('cbt_result_id', $ctx['result']->id)
            ->where('student_profile_id', $ctx['student']->id)
            ->count());
    }

    /**
     * @return array{user: \App\Models\User, student: \App\Models\StudentProfile, result: CbtResult}
     */
    private function submittedResult(): array
    {
        $ctx = $this->cbtPublishedExam();
        $user = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $user, $ctx['student']);
        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();
        app(CbtAnswerService::class)->save($attempt, $user, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ]);
        $result = app(CbtSubmissionService::class)->submit($attempt, $user);

        return ['user' => $user, 'student' => $ctx['student'], 'result' => $result];
    }

    private function grantAccess(array $ctx): void
    {
        $payment = OnlinePayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'SRS-PAY-GRANT-'.Str::upper(Str::random(6)),
            'provider' => 'paystack',
            'purpose' => OnlinePaymentPurpose::CbtResultChecker,
            'user_id' => $ctx['user']->id,
            'student_profile_id' => $ctx['student']->id,
            'email' => $ctx['user']->email,
            'amount_kobo' => 50000,
            'currency' => 'NGN',
            'status' => OnlinePaymentStatus::Paid,
            'paid_at' => now(),
            'metadata' => ['cbt_result_id' => $ctx['result']->id],
        ]);

        app(CbtResultCheckerService::class)->activateFromPayment($payment);
    }

    private function fakeInitialize(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/rc-test',
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
