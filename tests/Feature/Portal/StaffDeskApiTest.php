<?php

namespace Tests\Feature\Portal;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementStatus;
use App\Enums\AttendanceStatus;
use App\Enums\RoleSlug;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\TermResult;
use App\Models\TimetableSlot;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class StaffDeskApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_staff_home_has_live_hooks_and_no_mock_copy(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($teacher)
            ->get('/staff')
            ->assertOk()
            ->assertSee('data-page="staff-desk"', false)
            ->assertSee('data-metric="pupils"', false)
            ->assertSee('data-metric="attendance"', false)
            ->assertSee('data-metric="assignments"', false)
            ->assertSee('data-metric="average"', false)
            ->assertSee('data-metric="letters"', false)
            ->assertSee('data-schedule', false)
            ->assertSee('data-tasks', false)
            ->assertSee('data-forms', false)
            ->assertSee('data-notices', false)
            ->assertSee('data-dates', false)
            ->assertSee('data-week', false)
            ->assertSee('portal-staff-desk.js', false)
            ->assertSee('staff-desk.css', false)
            ->assertSee('href="/staff/attendance"', false)
            ->assertSee('<strong>Log out</strong>', false)
            ->assertDontSee('Mrs. Okafor', false)
            ->assertDontSee('91%', false)
            ->assertDontSee('Good morning, Mrs. Okafor', false);

        $js = (string) file_get_contents(public_path('site/JS/portal-staff-desk.js'));
        $this->assertStringContainsString('/api/v1/staff-desk', $js);
        $this->assertStringContainsString('DESK_POLL_MS', $js);
        $this->assertStringContainsString('visibilitychange', $js);
    }

    public function test_empty_desk_returns_zeroed_live_snapshot(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Daniel Okoro']);
        $this->staff($teacher, ['staff_number' => 'STAFF-19', 'job_title' => 'Class Teacher']);
        $this->settings(['name' => 'Supreme Reagan Schools']);

        $empty = $this->actingAs($teacher)
            ->getJson('/api/v1/staff-desk')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Faculty desk retrieved.')
            ->assertJsonPath('data.name', 'Daniel')
            ->assertJsonPath('data.full_name', 'Daniel Okoro')
            ->assertJsonPath('data.title', 'Class Teacher')
            ->assertJsonPath('data.staff_number', 'STAFF-19')
            ->assertJsonPath('data.initials', 'DO')
            ->assertJsonPath('data.metrics.pupils', 0)
            ->assertJsonPath('data.metrics.attendance_percent', null)
            ->assertJsonPath('data.metrics.attendance_delta', 'No roll to mark')
            ->assertJsonPath('data.metrics.assignments', 0)
            ->assertJsonPath('data.metrics.average_percent', null)
            ->assertJsonPath('data.metrics.letters', 0)
            ->assertJsonPath('data.house', null);

        $this->assertSame([], $empty->json('data.schedule'));
        $this->assertSame([], $empty->json('data.forms'));
        $this->assertCount(7, $empty->json('data.week'));
    }

    public function test_desk_reads_roll_bells_work_and_notices_from_the_ledger(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00', 'Africa/Lagos'));

        $teacher = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Daniel Okoro']);
        $staff = $this->staff($teacher, [
            'staff_number' => 'STAFF-19',
            'job_title' => 'Class Teacher',
        ]);
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $this->settings([
            'name' => 'Supreme Reagan Schools',
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);
        $offering = $this->offering(null, $session);
        $this->classTeacher($staff, $offering);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);

        $first = $this->student($this->userWithRole(RoleSlug::Student), [
            'surname' => 'Eze',
            'first_name' => 'Amaka',
        ]);
        $second = $this->student($this->userWithRole(RoleSlug::Student), [
            'surname' => 'Ibe',
            'first_name' => 'Chidi',
        ]);
        $enrollmentA = $this->enroll($first, $offering);
        $enrollmentB = $this->enroll($second, $offering);

        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollmentA->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2026-08-27',
            'status' => AttendanceStatus::Present,
            'marked_by' => $teacher->id,
        ]);
        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollmentB->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2026-08-27',
            'status' => AttendanceStatus::Absent,
            'marked_by' => $teacher->id,
        ]);

        TimetableSlot::query()->create([
            'class_section_offering_id' => $offering->id,
            'term_id' => $term->id,
            'day_of_week' => 4,
            'starts_at' => '09:40',
            'ends_at' => '10:20',
            'subject_id' => $subject->id,
            'staff_profile_id' => $staff->id,
        ]);

        Assignment::query()->create([
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
            'staff_profile_id' => $staff->id,
            'title' => 'Algebra sheet',
            'due_on' => '2026-08-27',
        ]);

        TermResult::query()->create([
            'enrollment_id' => $enrollmentA->id,
            'term_id' => $term->id,
            'subject_id' => $subject->id,
            'total' => 78,
        ]);

        Announcement::query()->create([
            'title' => 'Staff briefing',
            'body' => 'Hall at two.',
            'audience' => AnnouncementAudience::Staff,
            'status' => AnnouncementStatus::Published,
            'published_at' => now(),
            'created_by' => $teacher->id,
        ]);

        $desk = $this->actingAs($teacher)
            ->getJson('/api/v1/staff-desk')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Daniel')
            ->assertJsonPath('data.school', 'Supreme Reagan Schools')
            ->assertJsonPath('data.session', '2025/2026')
            ->assertJsonPath('data.term', 'First Term')
            ->assertJsonPath('data.metrics.pupils', 2)
            ->assertJsonPath('data.metrics.attendance_percent', 50)
            ->assertJsonPath('data.metrics.assignments', 1)
            ->assertJsonPath('data.metrics.average_percent', 78)
            ->assertJsonPath('data.schedule.0.subject', 'Mathematics')
            ->assertJsonPath('data.schedule.0.status', 'now')
            ->assertJsonPath('data.house.pupils', 2)
            ->assertJsonPath('data.house.present', 1)
            ->assertJsonPath('data.house.assignments', 1)
            ->assertJsonPath('data.notices.0.title', 'Staff briefing')
            ->assertJsonPath('data.dates.0.title', 'Algebra sheet');

        $titles = collect($desk->json('data.tasks'))->pluck('title');
        $this->assertTrue($titles->contains('Mathematics is in session'));
        $this->assertTrue($titles->contains('Work due'));
        $this->assertFalse($titles->contains('Take attendance'));

        Carbon::setTestNow();
    }

    public function test_admin_and_parent_cannot_read_the_faculty_desk(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/staff-desk')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->userWithRole(RoleSlug::Parent))
            ->getJson('/api/v1/staff-desk')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_guest_cannot_read_the_faculty_desk(): void
    {
        $this->getJson('/api/v1/staff-desk')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
