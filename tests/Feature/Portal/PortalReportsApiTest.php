<?php

namespace Tests\Feature\Portal;

use App\Enums\ApplicationStatus;
use App\Enums\AttendanceStatus;
use App\Enums\FeeChannel;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleSlug;
use App\Enums\StaffStatus;
use App\Models\AdmissionApplication;
use App\Models\AttendanceRecord;
use App\Models\Invoice;
use App\Models\Payment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalReportsApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_reports_page_has_live_hooks_and_no_mock_copy(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/reports')
            ->assertOk()
            ->assertSee('data-page="reports"', false)
            ->assertSee('data-metric="attendance"', false)
            ->assertSee('data-metric="fees"', false)
            ->assertSee('data-metric="admissions"', false)
            ->assertSee('data-metric="staff"', false)
            ->assertSee('data-report-cells', false)
            ->assertSee('data-report-wings', false)
            ->assertSee('data-report-pipeline', false)
            ->assertSee('data-report-week', false)
            ->assertSee('portal-reports.js', false)
            ->assertSee('data-generate-report', false)
            ->assertSee('data-export-report', false)
            ->assertSee('data-print-report', false)
            ->assertSee('data-draw="roll"', false)
            ->assertSee('data-draw="fees"', false)
            ->assertSee('data-draw="attendance"', false)
            ->assertSee('data-draw="staff"', false)
            ->assertDontSee('data-count="94.2"', false)
            ->assertDontSee('data-count="78"', false)
            ->assertDontSee('data-count="64"', false)
            ->assertDontSee('data-count="97"', false);

        $js = (string) file_get_contents(public_path('site/JS/portal-reports.js'));
        $this->assertStringContainsString('/api/v1/portal-reports', $js);
        $this->assertStringContainsString('/api/v1/portal-reports/catalogue', $js);
        $this->assertStringContainsString('/api/v1/portal-reports/generate', $js);
        $this->assertStringContainsString('/api/v1/portal-reports/export', $js);
        $this->assertStringContainsString('REPORT_POLL_MS', $js);
        $this->assertStringContainsString('visibilitychange', $js);
        $this->assertStringContainsString('cache: "no-store"', $js);
    }

    public function test_empty_assay_returns_zeroed_live_snapshot(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, ['name' => 'Ada Ibeaja']);

        $empty = $this->actingAs($admin)
            ->getJson('/api/v1/portal-reports')
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'School reports retrieved.')
            ->assertJsonPath('data.name', 'Ada Ibeaja')
            ->assertJsonPath('data.metrics.attendance_percent', null)
            ->assertJsonPath('data.metrics.attendance_delta', 'No roll marked this week')
            ->assertJsonPath('data.metrics.fees_percent', null)
            ->assertJsonPath('data.metrics.fees_label', '₦0')
            ->assertJsonPath('data.metrics.admissions', 0)
            ->assertJsonPath('data.metrics.staff_present', 0)
            ->assertJsonPath('data.metrics.staff_expected', 0)
            ->assertJsonPath('data.ledger.collected', '₦0')
            ->assertJsonPath('data.ledger.outstanding', '₦0')
            ->assertJsonPath('data.wings.0.slug', 'nursery')
            ->assertJsonPath('data.wings.0.pupils', 0)
            ->assertJsonPath('data.pipeline.0.status', 'submitted')
            ->assertJsonPath('data.pipeline.0.count', 0);

        $this->assertCount(7, $empty->json('data.week'));
    }

    public function test_assay_reads_roll_fees_admissions_and_staff_from_the_ledger(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 10:00:00', 'Africa/Lagos'));

        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, ['name' => 'Ada Ibeaja']);
        $session = $this->academicSession([
            'starts_on' => '2025-09-08',
            'ends_on' => '2026-09-30',
        ]);
        $term = $this->termFor($session);
        $this->level(['name' => 'Nursery', 'slug' => 'nursery', 'sort_order' => 1]);
        $this->level(['name' => 'Primary', 'slug' => 'primary', 'sort_order' => 2]);
        $campus = $this->campus();
        $offering = $this->offering(null, $session, $campus);
        $this->settings([
            'name' => 'Supreme Reagan Schools',
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);

        $student = $this->student($this->userWithRole(RoleSlug::Student), [
            'admitted_on' => '2025-09-08',
        ]);
        $enrollment = $this->enroll($student, $offering);

        $teacher = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Mr. Daniel Okoro']);
        $this->staff($teacher, ['staff_number' => 'STAFF-19']);
        $this->staff($this->userWithRole(RoleSlug::Teacher), [
            'status' => StaffStatus::Inactive,
        ]);

        $invoice = Invoice::query()->create([
            'number' => 'INV/2025/0881',
            'student_profile_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'status' => InvoiceStatus::Partial,
            'total_kobo' => 20000000,
            'paid_kobo' => 15000000,
        ]);
        Payment::query()->create([
            'reference' => 'FEE-881',
            'student_profile_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount_kobo' => 15000000,
            'channel' => FeeChannel::Transfer,
            'paid_at' => now(),
            'status' => PaymentStatus::Posted,
            'recorded_by' => $admin->id,
        ]);

        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $enrollment->class_section_offering_id,
            'marked_on' => '2026-08-26',
            'status' => AttendanceStatus::Present,
            'marked_by' => $teacher->id,
        ]);
        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $enrollment->class_section_offering_id,
            'marked_on' => '2026-08-24',
            'status' => AttendanceStatus::Absent,
            'marked_by' => $teacher->id,
        ]);

        AdmissionApplication::query()->create([
            'reference' => 'ADM-0001',
            'academic_session_id' => $session->id,
            'session_name' => '2025/2026',
            'class_applied' => 'JSS 1',
            'entry_term' => 'First Term',
            'surname' => 'Eze',
            'first_name' => 'Ifeanyi',
            'gender' => 'male',
            'date_of_birth' => '2014-03-12',
            'nationality' => 'Nigerian',
            'state_of_origin' => 'Imo',
            'home_address' => 'Owerri',
            'parent_name' => 'Mrs. Ngozi Eze',
            'relationship' => 'mother',
            'parent_phone' => '08031110011',
            'parent_email' => 'ngozi@example.test',
            'status' => ApplicationStatus::Submitted,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/portal-reports')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.school', 'Supreme Reagan Schools')
            ->assertJsonPath('data.session', '2025/2026')
            ->assertJsonPath('data.term', 'First Term')
            ->assertJsonPath('data.metrics.attendance_percent', 50)
            ->assertJsonPath('data.metrics.fees_percent', 75)
            ->assertJsonPath('data.metrics.fees_label', '₦150k')
            ->assertJsonPath('data.metrics.admissions', 1)
            ->assertJsonPath('data.metrics.staff_present', 1)
            ->assertJsonPath('data.metrics.staff_expected', 1)
            ->assertJsonPath('data.metrics.staff_percent', 100)
            ->assertJsonPath('data.ledger.outstanding', '₦50k')
            ->assertJsonPath('data.cells.0.key', 'roll')
            ->assertJsonPath('data.cells.0.value', 1)
            ->assertJsonPath('data.cells.2.key', 'attendance')
            ->assertJsonPath('data.cells.3.key', 'staff')
            ->assertJsonPath('data.ledger.partially_paid_count', 1)
            ->assertJsonPath('data.pipeline.0.status', 'submitted')
            ->assertJsonPath('data.pipeline.0.count', 1);

        Carbon::setTestNow();
    }

    public function test_teacher_cannot_read_the_assay(): void
    {
        $this->actingAs($this->userWithRole(RoleSlug::Teacher))
            ->getJson('/api/v1/portal-reports')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->userWithRole(RoleSlug::Teacher))
            ->getJson('/api/v1/portal-reports/catalogue')
            ->assertForbidden();

        $this->actingAs($this->userWithRole(RoleSlug::Teacher))
            ->getJson('/api/v1/portal-reports/generate?kind=roll')
            ->assertForbidden();

        $this->actingAs($this->userWithRole(RoleSlug::Teacher))
            ->get('/api/v1/portal-reports/export?kind=roll')
            ->assertForbidden();

        $this->actingAs($this->userWithRole(RoleSlug::Teacher))
            ->get('/portal/reports')
            ->assertRedirect(route('staff.home'));
    }

    public function test_guest_cannot_read_the_assay(): void
    {
        $this->getJson('/api/v1/portal-reports')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_admin_can_catalogue_and_draw_live_school_papers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 10:00:00', 'Africa/Lagos'));

        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, ['name' => 'Ada Ibeaja']);
        $session = $this->academicSession([
            'starts_on' => '2025-09-08',
            'ends_on' => '2026-09-30',
        ]);
        $term = $this->termFor($session);
        $campus = $this->campus();
        $offering = $this->offering(null, $session, $campus);
        $this->settings([
            'name' => 'Supreme Reagan Schools',
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);

        $student = $this->student($this->userWithRole(RoleSlug::Student));
        $enrollment = $this->enroll($student, $offering);

        $teacher = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Mr. Daniel Okoro']);
        $this->staff($teacher, ['staff_number' => 'STAFF-19']);

        $invoice = Invoice::query()->create([
            'number' => 'INV/2025/0881',
            'student_profile_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'status' => InvoiceStatus::Partial,
            'total_kobo' => 20000000,
            'paid_kobo' => 15000000,
        ]);
        Payment::query()->create([
            'reference' => 'FEE-881',
            'student_profile_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount_kobo' => 15000000,
            'channel' => FeeChannel::Transfer,
            'paid_at' => now(),
            'status' => PaymentStatus::Posted,
            'recorded_by' => $admin->id,
        ]);

        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $enrollment->class_section_offering_id,
            'marked_on' => '2026-08-26',
            'status' => AttendanceStatus::Present,
            'marked_by' => $teacher->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/portal-reports/catalogue')
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kinds.0.slug', 'roll')
            ->assertJsonPath('data.kinds.1.slug', 'fees')
            ->assertJsonPath('data.kinds.2.slug', 'attendance')
            ->assertJsonPath('data.kinds.3.slug', 'staff')
            ->assertJsonPath('data.current_academic_session_id', $session->id)
            ->assertJsonPath('data.offerings.0.id', $offering->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/portal-reports/generate?kind=roll')
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertJsonPath('data.kind', 'roll')
            ->assertJsonPath('data.rows.0.full_name', 'Okafor Chiamaka')
            ->assertJsonPath('data.rows.0.admission_number', $student->admission_number)
            ->assertJsonPath('data.summary.pupils', 1);

        $this->actingAs($admin)
            ->getJson('/api/v1/portal-reports/generate?kind=fees&academic_session_id='.$session->id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.kind', 'fees')
            ->assertJsonPath('data.rows.0.status', 'Partially Paid')
            ->assertJsonPath('data.summary.pupils', 1)
            ->assertJsonPath('data.summary.partially_paid', 1);

        $this->actingAs($admin)
            ->getJson('/api/v1/portal-reports/generate?kind=fees&status=paid_in_full')
            ->assertOk()
            ->assertJsonPath('data.summary.pupils', 0);

        $this->actingAs($admin)
            ->getJson('/api/v1/portal-reports/generate?kind=attendance&from=2026-08-24&to=2026-08-26')
            ->assertOk()
            ->assertJsonPath('data.kind', 'attendance')
            ->assertJsonPath('data.rows.0.present', 1)
            ->assertJsonPath('data.summary.percent', 100);

        $this->actingAs($admin)
            ->getJson('/api/v1/portal-reports/generate?kind=staff&from=2026-08-26&to=2026-08-26')
            ->assertOk()
            ->assertJsonPath('data.kind', 'staff')
            ->assertJsonPath('data.rows.0.roll_taken', 'Yes')
            ->assertJsonPath('data.summary.present', 1);

        $csv = $this->actingAs($admin)
            ->get('/api/v1/portal-reports/export?kind=roll')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('Pupil', $csv);
        $this->assertStringContainsString('Okafor Chiamaka', $csv);

        $this->actingAs($admin)
            ->getJson('/api/v1/portal-reports/generate?kind=marks')
            ->assertUnprocessable();

        Carbon::setTestNow();
    }
}
