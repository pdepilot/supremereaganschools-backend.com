<?php

namespace Tests\Feature\Fees;

use App\Enums\InvoiceStatus;
use App\Enums\RoleSlug;
use App\Models\Invoice;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_an_invoice_from_matching_fee_structures(): void
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(null, $session);
        $student = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $this->enroll($student, $offering);
        $this->feeStructure($this->feeType(['name' => 'Tuition', 'code' => 'TUI']), $session, $term, ['amount_kobo' => 18000000]);
        $this->feeStructure($this->feeType(['name' => 'ICT Fee', 'code' => 'ICT']), $session, $term, ['amount_kobo' => 2500000]);

        $this->actingAs($this->admin())->postJson('/api/v1/invoices', [
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.total_kobo', 20500000)
            ->assertJsonPath('data.enrollment_id', $offering->enrollments()->value('id'));
    }

    public function test_duplicate_invoice_for_the_same_student_and_term_is_rejected(): void
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(null, $session);
        $student = $this->student();
        $this->enroll($student, $offering);
        $this->feeStructure($this->feeType(), $session, $term);

        $this->actingAs($this->admin())->postJson('/api/v1/invoices', [
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
        ])->assertCreated();

        $this->actingAs($this->admin())->postJson('/api/v1/invoices', [
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('term_id');
    }

    public function test_bulk_generate_creates_invoices_for_enrolled_pupils_and_skips_duplicates(): void
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(null, $session);
        $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $offering);
        $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $offering);
        $this->feeStructure($this->feeType(), $session, $term, ['amount_kobo' => 100000]);

        $this->actingAs($this->admin())->postJson('/api/v1/invoices/generate', [
            'term_id' => $term->id,
        ])->assertOk()->assertJsonPath('data.created', 2);

        $this->actingAs($this->admin())->postJson('/api/v1/invoices/generate', [
            'term_id' => $term->id,
        ])->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.skipped', 2);
    }

    public function test_database_unique_constraint_blocks_two_invoices_for_the_same_term(): void
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(null, $session);
        $student = $this->student();
        $enrollment = $this->enroll($student, $offering);

        Invoice::query()->create([
            'number' => 'INV/2025/0001',
            'student_profile_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'status' => InvoiceStatus::Unpaid,
            'total_kobo' => 100,
            'paid_kobo' => 0,
        ]);

        $this->expectException(QueryException::class);

        Invoice::query()->create([
            'number' => 'INV/2025/0002',
            'student_profile_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'status' => InvoiceStatus::Unpaid,
            'total_kobo' => 100,
            'paid_kobo' => 0,
        ]);
    }

    public function test_admin_ledger_summary_reads_the_current_term(): void
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(null, $session);
        $this->settings([
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);
        $student = $this->student();
        $enrollment = $this->enroll($student, $offering);
        $admin = $this->admin();

        $invoice = Invoice::query()->create([
            'number' => 'INV/2025/0881',
            'student_profile_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'status' => InvoiceStatus::Partial,
            'total_kobo' => 10000000,
            'paid_kobo' => 2500000,
        ]);

        \App\Models\Payment::query()->create([
            'reference' => 'FEE-0881',
            'student_profile_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount_kobo' => 2500000,
            'channel' => 'transfer',
            'paid_at' => now('Africa/Lagos'),
            'status' => \App\Enums\PaymentStatus::Posted,
            'recorded_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/invoices/summary')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.term_id', $term->id)
            ->assertJsonPath('data.term_name', 'First Term')
            ->assertJsonPath('data.session_name', '2025/2026')
            ->assertJsonPath('data.invoice_count', 1)
            ->assertJsonPath('data.collection_share', 25)
            ->assertJsonPath('data.expected_kobo', 10000000)
            ->assertJsonPath('data.collected_kobo', 2500000)
            ->assertJsonPath('data.outstanding_kobo', 7500000)
            ->assertJsonPath('data.today_kobo', 2500000)
            ->assertJsonPath('data.expected.label', '₦100k');
    }

    public function test_admin_can_look_up_invoices_by_admission_number(): void
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(null, $session);
        $student = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $other = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']);
        $this->enroll($student, $offering);
        $this->enroll($other, $offering);
        $this->feeStructure($this->feeType(), $session, $term, ['amount_kobo' => 100000]);

        $this->actingAs($this->admin())->postJson('/api/v1/invoices', [
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
        ])->assertCreated();
        $this->actingAs($this->admin())->postJson('/api/v1/invoices', [
            'student_profile_id' => $other->id,
            'term_id' => $term->id,
        ])->assertCreated();

        $this->actingAs($this->admin())
            ->getJson('/api/v1/invoices?admission_number=SRS/2025/0142')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.admission_number', 'SRS/2025/0142');
    }

    public function test_admin_can_filter_the_ledger_by_status_class_session_and_search(): void
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $class = $this->schoolClass();
        $sectionA = $this->section($class, ['arm' => 'A', 'name' => $class->name.' A']);
        $sectionB = $this->section($class, ['arm' => 'B', 'name' => $class->name.' B']);
        $campus = $this->campus();
        $offeringA = $this->offering($sectionA, $session, $campus);
        $offeringB = $this->offering($sectionB, $session, $campus);
        $this->feeStructure($this->feeType(), $session, $term, ['amount_kobo' => 30000000]);

        $paid = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0142',
            'surname' => 'Doe',
            'first_name' => 'John',
        ]);
        $partial = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0198',
            'surname' => 'Smith',
            'first_name' => 'Jane',
        ]);
        $outstanding = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0221',
            'surname' => 'Okoro',
            'first_name' => 'David',
        ]);
        $this->enroll($paid, $offeringA);
        $this->enroll($partial, $offeringA);
        $this->enroll($outstanding, $offeringB);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/v1/invoices/generate', ['term_id' => $term->id])->assertOk();

        $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => $paid->admission_number,
            'amount' => 300000,
            'channel' => 'cash',
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => $partial->admission_number,
            'amount' => 150000,
            'channel' => 'transfer',
        ])->assertCreated();

        $this->actingAs($admin)
            ->getJson('/api/v1/invoices?status=paid_in_full&academic_session_id='.$session->id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fee_status', 'paid_in_full')
            ->assertJsonPath('data.0.fee_status_label', 'Paid in Full')
            ->assertJsonPath('data.0.balance_kobo', 0);

        $this->actingAs($admin)
            ->getJson('/api/v1/invoices?status=partially_paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.admission_number', 'SRS/2025/0198')
            ->assertJsonPath('data.0.paid_kobo', 15000000)
            ->assertJsonPath('data.0.balance_kobo', 15000000);

        $this->actingAs($admin)
            ->getJson('/api/v1/invoices?status=outstanding&class_section_id='.$sectionB->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.admission_number', 'SRS/2025/0221')
            ->assertJsonPath('data.0.paid_kobo', 0)
            ->assertJsonPath('data.0.balance_kobo', 30000000);

        $this->actingAs($admin)
            ->getJson('/api/v1/invoices?school_class_id='.$class->id.'&arm=A')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin)
            ->getJson('/api/v1/invoices?q=Okoro')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_name', $outstanding->fullName());
    }

    public function test_admin_summary_counts_paid_partial_and_outstanding(): void
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(null, $session);
        $this->settings([
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);
        $this->feeStructure($this->feeType(), $session, $term, ['amount_kobo' => 10000000]);
        $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $offering);
        $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $offering);
        $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0221']), $offering);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/v1/invoices/generate', ['term_id' => $term->id])->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => 'SRS/2025/0142',
            'amount' => 100000,
            'channel' => 'cash',
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/payments', [
            'admission_number' => 'SRS/2025/0198',
            'amount' => 40000,
            'channel' => 'pos',
        ])->assertCreated();

        $this->actingAs($admin)
            ->getJson('/api/v1/invoices/summary')
            ->assertOk()
            ->assertJsonPath('data.expected_kobo', 30000000)
            ->assertJsonPath('data.collected_kobo', 14000000)
            ->assertJsonPath('data.outstanding_kobo', 16000000)
            ->assertJsonPath('data.paid_in_full_count', 1)
            ->assertJsonPath('data.partially_paid_count', 1)
            ->assertJsonPath('data.outstanding_count', 1)
            ->assertJsonPath('data.students_with_fees', 3);
    }
}
