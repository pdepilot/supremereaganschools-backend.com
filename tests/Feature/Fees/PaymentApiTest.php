<?php

namespace Tests\Feature\Fees;

use App\Enums\FeeChannel;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleSlug;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_post_a_payment_against_an_admission_number(): void
    {
        [$student, $invoice] = $this->openInvoice(20500000);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'amount' => 185000,
            'channel' => 'Transfer',
            'note' => 'First term tuition',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.amount_kobo', 18500000)
            ->assertJsonPath('data.recorded_by', $admin->name);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Partial, $invoice->status);
        $this->assertSame(18500000, $invoice->paid_kobo);
    }

    public function test_overpayment_is_rejected_and_writes_nothing(): void
    {
        [$student] = $this->openInvoice(100000);

        $this->actingAs($this->admin())->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'amount' => 2000,
            'channel' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_payment_is_allocated_across_invoice_lines(): void
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(null, $session);
        $student = $this->student();
        $enrollment = $this->enroll($student, $offering);
        $tuition = $this->feeType(['name' => 'Tuition', 'code' => 'TUI']);
        $ict = $this->feeType(['name' => 'ICT Fee', 'code' => 'ICT']);
        $invoice = Invoice::query()->create([
            'number' => 'INV/2025/0009',
            'student_profile_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'status' => InvoiceStatus::Unpaid,
            'total_kobo' => 150000,
            'paid_kobo' => 0,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'fee_type_id' => $tuition->id,
            'description' => 'Tuition',
            'amount_kobo' => 100000,
            'paid_kobo' => 0,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'fee_type_id' => $ict->id,
            'description' => 'ICT Fee',
            'amount_kobo' => 50000,
            'paid_kobo' => 0,
        ]);

        $this->actingAs($this->admin())->postJson('/api/v1/payments', [
            'student_profile_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount_kobo' => 120000,
            'channel' => FeeChannel::Cash->value,
        ])->assertCreated();

        $invoice->refresh();
        $items = $invoice->items()->orderBy('id')->get()->values();
        $this->assertSame(120000, $invoice->paid_kobo);
        $this->assertCount(2, $items);
        $this->assertSame(100000, $items[0]->paid_kobo);
        $this->assertSame(20000, $items[1]->paid_kobo);
    }

    public function test_voiding_a_payment_restores_the_invoice_balance(): void
    {
        [$student, $invoice] = $this->openInvoice(100000);
        $admin = $this->admin();

        $created = $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'amount_kobo' => 40000,
            'channel' => 'pos',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/payments/'.$created->json('data.id').'/void', [
            'void_reason' => 'Posted to the wrong pupil.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'void')
            ->assertJsonPath('data.void_reason', 'Posted to the wrong pupil.');

        $invoice->refresh();
        $this->assertSame(0, $invoice->paid_kobo);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertDatabaseHas('payments', [
            'id' => $created->json('data.id'),
            'status' => PaymentStatus::Void->value,
        ]);
    }

    public function test_recorded_by_cannot_be_mass_assigned_from_the_request(): void
    {
        [$student] = $this->openInvoice(100000);
        $admin = $this->admin();
        $stranger = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'amount_kobo' => 1000,
            'channel' => 'cash',
            'recorded_by' => $stranger->id,
        ])->assertCreated()->assertJsonPath('data.recorded_by', $admin->name);

        $this->assertDatabaseHas('payments', [
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_payments_are_never_deleted(): void
    {
        $payment = new Payment;
        $this->assertFalse($this->admin()->can('delete', $payment));
    }

    public function test_admin_can_list_recent_posted_payments(): void
    {
        [$student, $invoice] = $this->openInvoice(100000);
        $admin = $this->admin();

        Payment::query()->create([
            'reference' => 'FEE-VOID',
            'student_profile_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount_kobo' => 1000,
            'channel' => FeeChannel::Cash,
            'paid_at' => now()->subDay(),
            'status' => PaymentStatus::Void,
            'recorded_by' => $admin->id,
        ]);
        Payment::query()->create([
            'reference' => 'FEE-POST',
            'student_profile_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount_kobo' => 2000,
            'channel' => FeeChannel::Transfer,
            'paid_at' => now(),
            'status' => PaymentStatus::Posted,
            'recorded_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/payments?status=posted&limit=8')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'FEE-POST')
            ->assertJsonPath('data.0.status', 'posted');
    }

    public function test_successful_payments_reduce_balance_and_preserve_history(): void
    {
        [$student, $invoice] = $this->openInvoice(30000000);
        $admin = $this->admin();

        $first = $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'channel' => 'transfer',
        ])->assertCreated();

        $this->assertStringStartsWith('SRS-FEE-'.now('Africa/Lagos')->year.'-', $first->json('data.reference'));
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Partial, $invoice->status);
        $this->assertSame(10000000, $invoice->paid_kobo);

        $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'invoice_id' => $invoice->id,
            'amount' => 200000,
            'channel' => 'cash',
        ])->assertCreated();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(30000000, $invoice->paid_kobo);
        $this->assertSame(0, $invoice->remainingKobo());
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_pending_and_failed_payments_do_not_reduce_the_balance(): void
    {
        [$student, $invoice] = $this->openInvoice(30000000);

        $this->actingAs($this->admin())->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'channel' => 'transfer',
            'status' => PaymentStatus::Pending->value,
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $invoice->refresh();
        $this->assertSame(0, $invoice->paid_kobo);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);

        $this->actingAs($this->admin())->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'invoice_id' => $invoice->id,
            'amount' => 50000,
            'channel' => 'pos',
            'status' => PaymentStatus::Failed->value,
        ])->assertCreated()->assertJsonPath('data.status', 'failed');

        $invoice->refresh();
        $this->assertSame(0, $invoice->paid_kobo);
        $this->assertSame(30000000, $invoice->remainingKobo());
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_duplicate_payment_reference_is_rejected(): void
    {
        [$student] = $this->openInvoice(30000000);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'amount' => 10000,
            'channel' => 'cash',
            'reference' => 'SRS-FEE-2026-000099',
        ])->assertCreated()->assertJsonPath('data.reference', 'SRS-FEE-2026-000099');

        $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'amount' => 10000,
            'channel' => 'cash',
            'reference' => 'SRS-FEE-2026-000099',
        ])->assertUnprocessable()->assertJsonValidationErrors('reference');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_custom_reference_and_payment_date_are_stored(): void
    {
        [$student] = $this->openInvoice(100000);

        $this->actingAs($this->admin())->postJson('/api/v1/payments', [
            'admission_number' => $student->admission_number,
            'amount_kobo' => 40000,
            'channel' => 'transfer',
            'reference' => 'srs-fee-2026-office-1',
            'paid_at' => '2026-09-15 10:00:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.reference', 'SRS-FEE-2026-OFFICE-1');

        $this->assertDatabaseHas('payments', [
            'reference' => 'SRS-FEE-2026-OFFICE-1',
            'amount_kobo' => 40000,
        ]);
    }

    /**
     * @return array{0: \App\Models\StudentProfile, 1: Invoice}
     */
    private function openInvoice(int $totalKobo): array
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(null, $session);
        $student = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $enrollment = $this->enroll($student, $offering);
        $type = $this->feeType();
        $invoice = Invoice::query()->create([
            'number' => 'INV/2025/0100',
            'student_profile_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'status' => InvoiceStatus::Unpaid,
            'total_kobo' => $totalKobo,
            'paid_kobo' => 0,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'fee_type_id' => $type->id,
            'description' => 'Tuition',
            'amount_kobo' => $totalKobo,
            'paid_kobo' => 0,
        ]);

        return [$student, $invoice];
    }
}
