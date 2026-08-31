<?php

namespace Tests\Feature\Portal;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\AttendanceStatus;
use App\Enums\EnquiryStatus;
use App\Enums\FeeChannel;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleSlug;
use App\Enums\StaffStatus;
use App\Enums\StudentStatus;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\ContactEnquiry;
use App\Models\Invoice;
use App\Models\Payment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalDashboardApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_command_desk_page_has_live_hooks_and_no_mock_copy(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/dashboard')
            ->assertOk()
            ->assertSee('data-page="dashboard"', false)
            ->assertSee('data-metric="pupils"', false)
            ->assertSee('data-ticket-list', false)
            ->assertSee('data-inbox-list', false)
            ->assertSee('data-pupil-lookup', false)
            ->assertSee('data-wing-cell="nursery"', false)
            ->assertSee('portal-dashboard.js', false)
            ->assertDontSee('Mrs. Ibeaja', false)
            ->assertDontSee('Chiamaka Okafor', false)
            ->assertDontSee('Mr. Daniel Okoro', false)
            ->assertDontSee('Mrs. Ngozi Eze', false)
            ->assertDontSee('1248', false)
            ->assertDontSee('48.6', false)
            ->assertDontSee('₦13.8M', false)
            ->assertDontSee('ADM-214', false);
    }

    public function test_empty_command_desk_returns_zeroed_live_snapshot(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, ['name' => 'Ada Ibeaja']);

        $this->actingAs($admin)
            ->getJson('/api/v1/portal-dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Command desk retrieved.')
            ->assertJsonPath('data.name', 'Ada Ibeaja')
            ->assertJsonPath('data.metrics.pupils', 0)
            ->assertJsonPath('data.metrics.staff', 0)
            ->assertJsonPath('data.metrics.forms', 0)
            ->assertJsonPath('data.metrics.fees_count', 0)
            ->assertJsonPath('data.metrics.fees_label', '₦0')
            ->assertJsonPath('data.metrics.fees_delta', 'Posted collections')
            ->assertJsonPath('data.metrics.attendance_percent', null)
            ->assertJsonPath('data.metrics.attendance_delta', 'No roll marked yet')
            ->assertJsonPath('data.house.copy', 'No session sealed yet')
            ->assertJsonPath('data.house.session', '—')
            ->assertJsonPath('data.house.term', '—')
            ->assertJsonPath('data.house.levels', 'None')
            ->assertJsonPath('data.house.outstanding', '₦0')
            ->assertJsonPath('data.tickets', [])
            ->assertJsonPath('data.inbox', [])
            ->assertJsonPath('data.wings.0.slug', 'nursery')
            ->assertJsonPath('data.wings.0.metrics.pupils', 0)
            ->assertJsonPath('data.wings.1.slug', 'primary')
            ->assertJsonPath('data.wings.2.slug', 'secondary');
    }

    public function test_command_desk_reads_pupils_staff_fees_and_inbox_from_the_ledger(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, ['name' => 'Ada Ibeaja']);
        $session = $this->academicSession();
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
            'admission_number' => 'ADM-214',
            'surname' => 'Okafor',
            'first_name' => 'Chiamaka',
        ]);
        $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0999',
            'status' => StudentStatus::Inactive,
        ]);
        $enrollment = $this->enroll($student, $offering);

        $teacher = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Mr. Daniel Okoro']);
        $this->staff($teacher, [
            'staff_number' => 'STAFF-19',
            'job_title' => 'Master',
        ]);
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
            'total_kobo' => 20500000,
            'paid_kobo' => 18500000,
        ]);
        Payment::query()->create([
            'reference' => 'FEE-881',
            'student_profile_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount_kobo' => 18500000,
            'channel' => FeeChannel::Transfer,
            'paid_at' => now(),
            'status' => PaymentStatus::Posted,
            'recorded_by' => $admin->id,
        ]);

        Announcement::query()->create([
            'title' => 'Mid-term examination notice',
            'body' => 'Examinations begin next week.',
            'category' => AnnouncementCategory::Academic,
            'audience' => AnnouncementAudience::WholeSchool,
            'status' => AnnouncementStatus::Published,
            'published_at' => now(),
            'created_by' => $admin->id,
        ]);

        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $enrollment->class_section_offering_id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Present,
            'marked_by' => $admin->id,
        ]);

        ContactEnquiry::query()->create([
            'name' => 'Mrs. Ngozi Eze',
            'phone' => '08031110011',
            'email' => 'ngozi@example.test',
            'subject' => 'Admission enquiry',
            'message' => 'Requesting a campus visit for her son.',
            'status' => EnquiryStatus::Unread,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/portal-dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Ada Ibeaja')
            ->assertJsonPath('data.school', 'Supreme Reagan Schools')
            ->assertJsonPath('data.metrics.pupils', 1)
            ->assertJsonPath('data.metrics.pupils_delta', 'Active on roll')
            ->assertJsonPath('data.metrics.staff', 1)
            ->assertJsonPath('data.metrics.forms', 1)
            ->assertJsonPath('data.metrics.fees_label', '₦185k')
            ->assertJsonPath('data.metrics.fees_delta', '90% of the ledger')
            ->assertJsonPath('data.metrics.attendance_percent', 100)
            ->assertJsonPath('data.house.session', '25/26')
            ->assertJsonPath('data.house.term', 'First Term')
            ->assertJsonPath('data.house.levels', 'Three')
            ->assertJsonPath('data.house.outstanding', '₦20k');

        $tickets = collect($response->json('data.tickets'));
        $this->assertNotEmpty($tickets);
        $this->assertTrue($tickets->contains(fn (array $ticket) => ($ticket['code'] ?? '') === 'ADM-214'));
        $this->assertTrue($tickets->contains(fn (array $ticket) => ($ticket['code'] ?? '') === 'FEE-881'));
        $this->assertTrue($tickets->contains(fn (array $ticket) => str_contains((string) ($ticket['title'] ?? ''), 'Mid-term')));

        $inbox = collect($response->json('data.inbox'));
        $this->assertTrue($inbox->contains(fn (array $item) => ($item['name'] ?? '') === 'Mrs. Ngozi Eze'));
        $this->assertStringContainsString('Junior Secondary', (string) $response->json('data.metrics.forms_delta'));
        $this->assertStringContainsString('Owerri', (string) $response->json('data.house.copy'));
    }

    public function test_teacher_cannot_read_the_command_desk(): void
    {
        $this->actingAs($this->userWithRole(RoleSlug::Teacher))
            ->getJson('/api/v1/portal-dashboard')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_guest_cannot_read_the_command_desk(): void
    {
        $this->getJson('/api/v1/portal-dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
