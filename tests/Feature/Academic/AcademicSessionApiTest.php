<?php

namespace Tests\Feature\Academic;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AcademicSessionApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_list_and_retrieve_an_academic_session(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2026/2027',
                'starts_on' => '2026-09-07',
                'ends_on' => '2027-07-23',
                'term_count' => 3,
                'status' => 'planned',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', '2026/2027')
            ->assertJsonPath('data.status', 'planned')
            ->assertJsonCount(3, 'data.terms');

        $session = AcademicSession::query()->where('name', '2026/2027')->first();

        $this->actingAs($admin)
            ->getJson('/api/v1/academic-sessions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', '2026/2027');

        $this->actingAs($admin)
            ->getJson('/api/v1/academic-sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $session->id);
    }

    public function test_session_validation_rejects_bad_dates_and_duplicate_names(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['name', 'starts_on', 'ends_on', 'term_count']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2026/2027',
                'starts_on' => '2027-07-23',
                'ends_on' => '2026-09-07',
                'term_count' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['ends_on']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2025/2026',
                'starts_on' => '2025-09-08',
                'ends_on' => '2026-07-24',
                'term_count' => 3,
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2025/2026',
                'starts_on' => '2025-09-08',
                'ends_on' => '2026-07-24',
                'term_count' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_activating_a_session_archives_the_previous_active_session(): void
    {
        $this->settings();
        $admin = $this->admin();

        $first = $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2024/2025',
                'starts_on' => '2024-09-09',
                'ends_on' => '2025-07-25',
                'term_count' => 3,
                'status' => 'active',
            ])
            ->assertCreated()
            ->json('data');

        $second = $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2025/2026',
                'starts_on' => '2025-09-08',
                'ends_on' => '2026-07-24',
                'term_count' => 3,
                'status' => 'planned',
            ])
            ->json('data');

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions/'.$second['id'].'/activate')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('academic_sessions', [
            'id' => $first['id'],
            'status' => SessionStatus::Archived->value,
        ]);
        $this->assertDatabaseHas('academic_sessions', [
            'id' => $second['id'],
            'status' => SessionStatus::Active->value,
        ]);
        $this->assertDatabaseHas('school_settings', [
            'current_academic_session_id' => $second['id'],
            'current_term_id' => $second['terms'][0]['id'],
        ]);
        $this->assertDatabaseHas('terms', [
            'id' => $second['terms'][0]['id'],
            'status' => SessionStatus::Active->value,
        ]);
        $this->assertNotNull(AcademicSession::query()->find($first['id']));
    }

    public function test_archiving_the_current_session_clears_the_desk(): void
    {
        $this->settings();
        $admin = $this->admin();

        $session = $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2025/2026',
                'starts_on' => '2025-09-08',
                'ends_on' => '2026-07-24',
                'term_count' => 3,
                'status' => 'active',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertDatabaseHas('school_settings', [
            'current_academic_session_id' => $session['id'],
            'current_term_id' => $session['terms'][0]['id'],
        ]);

        $this->actingAs($admin)
            ->putJson('/api/v1/academic-sessions/'.$session['id'], [
                'status' => 'archived',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertNull(\App\Models\SchoolSetting::query()->value('current_academic_session_id'));
        $this->assertNull(\App\Models\SchoolSetting::query()->value('current_term_id'));
    }

    public function test_an_empty_session_can_be_deleted(): void
    {
        $admin = $this->admin();

        $session = $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2026/2027',
                'starts_on' => '2026-09-07',
                'ends_on' => '2027-07-23',
                'term_count' => 2,
            ])
            ->json('data');

        $this->actingAs($admin)
            ->deleteJson('/api/v1/academic-sessions/'.$session['id'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('academic_sessions', ['id' => $session['id']]);
        $this->assertDatabaseMissing('terms', ['academic_session_id' => $session['id']]);
    }

    public function test_deleting_a_session_detaches_admission_applications(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $this->termFor($session);

        $application = \App\Models\AdmissionApplication::query()->create([
            'reference' => 'APP-TEST-001',
            'academic_session_id' => $session->id,
            'session_name' => $session->name,
            'class_applied' => 'JSS 1',
            'entry_term' => 'First Term',
            'surname' => 'Eze',
            'first_name' => 'Ifeanyi',
            'gender' => \App\Enums\Gender::Male,
            'date_of_birth' => '2014-03-12',
            'nationality' => 'Nigerian',
            'state_of_origin' => 'Imo',
            'home_address' => 'Amakohia-Akwakuma, Owerri',
            'parent_name' => 'Mrs. Ngozi Eze',
            'relationship' => \App\Enums\GuardianRelationship::Mother,
            'parent_phone' => '08031110011',
            'parent_email' => 'ngozi.visit@example.test',
            'status' => \App\Enums\ApplicationStatus::Submitted,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/academic-sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('academic_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('admission_applications', [
            'id' => $application->id,
            'academic_session_id' => null,
            'session_name' => $session->name,
        ]);
    }

    public function test_a_session_with_empty_forms_can_be_deleted(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $offering = $this->offering(session: $session);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/academic-sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('academic_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('class_section_offerings', ['id' => $offering->id]);
    }

    public function test_a_session_with_empty_fee_book_can_be_deleted(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $structure = $this->feeStructure($this->feeType(), $session, $term, ['amount_kobo' => 15000000]);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/academic-sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('academic_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('fee_structures', ['id' => $structure->id]);
    }

    public function test_a_session_with_enrolled_forms_can_be_deleted(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $offering = $this->offering(session: $session);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/academic-sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('academic_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('class_section_offerings', ['id' => $offering->id]);
        $this->assertDatabaseMissing('enrollments', ['id' => $enrollment->id]);
    }

    public function test_a_session_with_withdrawn_roll_rows_can_be_deleted(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $offering = $this->offering(session: $session);
        $enrollment = $this->enroll($this->student(), $offering, [
            'status' => \App\Enums\EnrollmentStatus::Withdrawn,
            'left_on' => now()->toDateString(),
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/academic-sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseMissing('academic_sessions', ['id' => $session->id]);
    }

    public function test_a_session_with_invoices_can_be_deleted(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $student = $this->student();
        $type = $this->feeType();

        $invoice = \App\Models\Invoice::query()->create([
            'number' => 'INV-TEST-001',
            'student_profile_id' => $student->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'status' => \App\Enums\InvoiceStatus::Partial,
            'total_kobo' => 10000000,
            'paid_kobo' => 2500000,
        ]);

        $item = \App\Models\InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'fee_type_id' => $type->id,
            'description' => 'Tuition',
            'amount_kobo' => 10000000,
            'paid_kobo' => 2500000,
        ]);

        $payment = \App\Models\Payment::query()->create([
            'reference' => 'FEE-TEST-001',
            'student_profile_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount_kobo' => 2500000,
            'channel' => 'transfer',
            'paid_at' => now('Africa/Lagos'),
            'status' => \App\Enums\PaymentStatus::Posted,
            'recorded_by' => $admin->id,
        ]);

        \App\Models\PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_item_id' => $item->id,
            'amount_kobo' => 2500000,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/academic-sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('academic_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_missing_session_returns_not_found_envelope(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/academic-sessions/9999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    public function test_unauthenticated_and_unauthorized_users_cannot_manage_sessions(): void
    {
        $this->postJson('/api/v1/academic-sessions', [
            'name' => '2026/2027',
            'starts_on' => '2026-09-07',
            'ends_on' => '2027-07-23',
            'term_count' => 3,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->actingAs($this->userWithRole(\App\Enums\RoleSlug::Teacher))
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2026/2027',
                'starts_on' => '2026-09-07',
                'ends_on' => '2027-07-23',
                'term_count' => 3,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    }
}
