<?php

namespace Tests\Feature\Fees;

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

class FeesAuthorizationTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    private mixed $feeSession = null;

    private mixed $feeTerm = null;

    private mixed $feeOffering = null;

    private mixed $feeType = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_parent_can_view_own_child_invoice_but_not_another_child(): void
    {
        [$childA, $invoiceA] = $this->invoiceFor('SRS/2025/0142');
        [$childB, $invoiceB] = $this->invoiceFor('SRS/2025/0198');
        $parentA = $this->userWithRole(RoleSlug::Parent);
        $parentB = $this->userWithRole(RoleSlug::Parent, ['email' => 'parent-b@school.test']);
        $this->linkGuardian($this->guardian($parentA, ['email' => $parentA->email]), $childA);
        $this->linkGuardian($this->guardian($parentB, ['full_name' => 'Mr. Okoro', 'email' => $parentB->email]), $childB);

        $this->actingAs($parentA)->getJson('/api/v1/invoices/'.$invoiceA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $invoiceA->id);

        $this->actingAs($parentA)->getJson('/api/v1/invoices/'.$invoiceB->id)
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($parentA)->getJson('/api/v1/invoices?student_profile_id='.$childB->id)
            ->assertForbidden();

        $this->actingAs($parentA)->postJson('/api/v1/payments', [
            'admission_number' => $childA->admission_number,
            'amount' => 1000,
            'channel' => 'cash',
        ])->assertForbidden();
    }

    public function test_student_can_view_own_invoice_but_not_another_students(): void
    {
        [$studentA, $invoiceA] = $this->invoiceFor('SRS/2025/0142');
        [, $invoiceB] = $this->invoiceFor('SRS/2025/0198');

        $this->actingAs($studentA->user)->getJson('/api/v1/invoices/'.$invoiceA->id)->assertOk();
        $this->actingAs($studentA->user)->getJson('/api/v1/invoices/'.$invoiceB->id)->assertForbidden();
        $this->actingAs($studentA->user)->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_teacher_cannot_read_or_post_fees(): void
    {
        [, $invoice] = $this->invoiceFor('SRS/2025/0142');
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($teacher);

        $this->actingAs($teacher)->getJson('/api/v1/invoices')->assertForbidden();
        $this->actingAs($teacher)->getJson('/api/v1/invoices/'.$invoice->id)->assertForbidden();
        $this->actingAs($teacher)->getJson('/api/v1/invoices/summary')->assertForbidden();
        $this->actingAs($teacher)->postJson('/api/v1/payments', [
            'admission_number' => 'SRS/2025/0142',
            'amount' => 1000,
            'channel' => 'cash',
        ])->assertForbidden();
    }

    public function test_unauthenticated_fee_access_is_rejected(): void
    {
        $this->getJson('/api/v1/invoices')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->postJson('/api/v1/payments', [
            'admission_number' => 'SRS/2025/0142',
            'amount' => 1000,
            'channel' => 'cash',
        ])->assertUnauthorized();
    }

    public function test_parent_cannot_view_another_childs_payment(): void
    {
        [$childA] = $this->invoiceFor('SRS/2025/0142');
        [$childB, $invoiceB] = $this->invoiceFor('SRS/2025/0198');
        $parentA = $this->userWithRole(RoleSlug::Parent);
        $this->linkGuardian($this->guardian($parentA, ['email' => $parentA->email]), $childA);

        $payment = Payment::query()->create([
            'reference' => 'FEE-0009',
            'student_profile_id' => $childB->id,
            'invoice_id' => $invoiceB->id,
            'amount_kobo' => 1000,
            'channel' => 'cash',
            'paid_at' => now(),
            'status' => PaymentStatus::Posted,
            'recorded_by' => $this->admin()->id,
        ]);

        $this->actingAs($parentA)->getJson('/api/v1/payments/'.$payment->id)->assertForbidden();
        $this->actingAs($parentA)->getJson('/api/v1/payments?student_profile_id='.$childB->id)->assertForbidden();
        $this->actingAs($parentA)->getJson('/api/v1/invoices/summary')->assertForbidden();
    }

    public function test_class_teacher_cannot_read_financial_records(): void
    {
        [$student, $invoice] = $this->invoiceFor('SRS/2025/0142');
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacher), $this->feeOffering);

        $payment = Payment::query()->create([
            'reference' => 'SRS-FEE-2026-000009',
            'student_profile_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount_kobo' => 1000,
            'channel' => 'cash',
            'paid_at' => now(),
            'status' => PaymentStatus::Posted,
            'recorded_by' => $this->admin()->id,
        ]);

        $this->actingAs($teacher)->getJson('/api/v1/invoices')->assertForbidden();
        $this->actingAs($teacher)->getJson('/api/v1/invoices/'.$invoice->id)->assertForbidden();
        $this->actingAs($teacher)->getJson('/api/v1/payments/'.$payment->id)->assertForbidden();
        $this->actingAs($teacher)->getJson('/api/v1/students/'.$student->id.'/fees')->assertForbidden();
        $this->actingAs($teacher)->getJson('/api/v1/me/fees')->assertForbidden();
    }

    public function test_student_and_parent_me_fee_routes_are_scoped(): void
    {
        [$childA, $invoiceA] = $this->invoiceFor('SRS/2025/0142');
        [$childB, $invoiceB] = $this->invoiceFor('SRS/2025/0198');
        $parent = $this->userWithRole(RoleSlug::Parent);
        $this->linkGuardian($this->guardian($parent, ['email' => $parent->email]), $childA);

        $this->actingAs($childA->user)->getJson('/api/v1/me/fees')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoiceA->id);

        $this->actingAs($childA->user)->getJson('/api/v1/students/'.$childB->id.'/fees')->assertForbidden();
        $this->actingAs($childA->user)->getJson('/api/v1/students/'.$childB->id.'/fees/summary')->assertForbidden();
        $this->actingAs($childA->user)->getJson('/api/v1/me/payments')->assertOk();

        $this->actingAs($parent)->getJson('/api/v1/students/'.$childA->id.'/fees')
            ->assertOk()
            ->assertJsonPath('data.0.id', $invoiceA->id);
        $this->actingAs($parent)->getJson('/api/v1/students/'.$childB->id.'/fees')->assertForbidden();
        $this->actingAs($parent)->getJson('/api/v1/students/'.$childB->id.'/fees/summary')->assertForbidden();
        $this->actingAs($parent)->getJson('/api/v1/invoices/'.$invoiceB->id)->assertForbidden();
    }

    public function test_guest_cannot_read_me_fees(): void
    {
        $this->getJson('/api/v1/me/fees')->assertUnauthorized();
        $this->getJson('/api/v1/me/payments')->assertUnauthorized();
    }

    /**
     * @return array{0: \App\Models\StudentProfile, 1: Invoice}
     */
    private function invoiceFor(string $admission): array
    {
        $session = $this->feeSession ??= $this->academicSession();
        $term = $this->feeTerm ??= $this->termFor($session);
        $offering = $this->feeOffering ??= $this->offering(null, $session);
        $type = $this->feeType ??= $this->feeType();

        $student = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => $admission]);
        $enrollment = $this->enroll($student, $offering);
        $invoice = Invoice::query()->create([
            'number' => 'INV/2025/'.$student->id,
            'student_profile_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'status' => InvoiceStatus::Unpaid,
            'total_kobo' => 100000,
            'paid_kobo' => 0,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'fee_type_id' => $type->id,
            'description' => 'Tuition',
            'amount_kobo' => 100000,
            'paid_kobo' => 0,
        ]);

        return [$student, $invoice];
    }
}
