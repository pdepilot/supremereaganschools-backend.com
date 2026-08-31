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
use App\Models\TermSummary;
use App\Models\TimetableSlot;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class StudentDeskApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_student_home_has_live_hooks_and_no_mock_copy(): void
    {
        $pupil = $this->userWithRole(RoleSlug::Student);
        $this->student($pupil);

        $this->actingAs($pupil)
            ->get('/student/home')
            ->assertOk()
            ->assertSee('data-page="student-desk"', false)
            ->assertSee('faculty-house', false)
            ->assertSee('staff-desk.css', false)
            ->assertSee('data-greeting', false)
            ->assertSee('data-clock', false)
            ->assertSee('data-metric="average"', false)
            ->assertSee('data-metric="attendance"', false)
            ->assertSee('data-metric="position"', false)
            ->assertSee('data-metric="assignments"', false)
            ->assertSee('data-metric="letters"', false)
            ->assertSee('data-schedule', false)
            ->assertSee('data-assignments', false)
            ->assertSee('data-notices', false)
            ->assertSee('data-class-teacher', false)
            ->assertSee('data-logout', false)
            ->assertSee('<strong>Log out</strong>', false)
            ->assertSee('portal-student-desk.js', false)
            ->assertSee('href="/student/timetable"', false)
            ->assertSee('href="/student/assignments"', false)
            ->assertDontSee('Chiamaka Nwosu', false)
            ->assertDontSee('SRS/2025/0142', false)
            ->assertDontSee('Mrs. Eze', false)
            ->assertDontSee('Algebra Exercise', false)
            ->assertDontSee('Mid-Term Examination Schedule', false)
            ->assertDontSee('Friday, August 21', false)
            ->assertDontSee('82%', false)
            ->assertDontSee('94%', false);

        $this->actingAs($pupil)
            ->get('/student')
            ->assertOk()
            ->assertSee('data-page="student-desk"', false);

        $js = (string) file_get_contents(public_path('site/JS/portal-student-desk.js'));
        $this->assertStringContainsString('/api/v1/student-desk', $js);
        $this->assertStringContainsString('DESK_POLL_MS', $js);
        $this->assertStringContainsString('visibilitychange', $js);
        $this->assertStringContainsString('data-clock', $js);
        $this->assertStringContainsString('data-dial', $js);
    }

    public function test_empty_desk_returns_zeroed_live_snapshot(): void
    {
        $pupil = $this->userWithRole(RoleSlug::Student, ['name' => 'Amaka Eze']);
        $profile = $this->student($pupil, [
            'admission_number' => 'SRS/2025/0411',
            'surname' => 'Eze',
            'first_name' => 'Amaka',
        ]);
        $this->settings(['name' => 'Supreme Reagan Schools']);

        $empty = $this->actingAs($pupil)
            ->getJson('/api/v1/student-desk')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pupil desk retrieved.')
            ->assertJsonPath('data.name', 'Amaka')
            ->assertJsonPath('data.full_name', 'Eze Amaka')
            ->assertJsonPath('data.admission_number', 'SRS/2025/0411')
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.initials', 'EA')
            ->assertJsonPath('data.form', null)
            ->assertJsonPath('data.class_teacher', null)
            ->assertJsonPath('data.metrics.average_percent', null)
            ->assertJsonPath('data.metrics.attendance_percent', null)
            ->assertJsonPath('data.metrics.attendance_delta', 'No class assigned yet')
            ->assertJsonPath('data.metrics.assignments', 0)
            ->assertJsonPath('data.metrics.letters', 0)
            ->assertJsonPath('data.metrics.class_position', null);

        $this->assertSame([], $empty->json('data.schedule'));
        $this->assertSame([], $empty->json('data.assignments'));
    }

    public function test_desk_reads_form_bells_work_and_notices_from_the_ledger(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00', 'Africa/Lagos'));

        $pupil = $this->userWithRole(RoleSlug::Student, ['name' => 'Amaka Eze']);
        $student = $this->student($pupil, [
            'admission_number' => 'SRS/2025/0412',
            'surname' => 'Eze',
            'first_name' => 'Amaka',
        ]);
        $teacher = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Daniel Okoro']);
        $staff = $this->staff($teacher, ['job_title' => 'Class Teacher']);
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $this->settings([
            'name' => 'Supreme Reagan Schools',
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);
        $offering = $this->offering(null, $session);
        $this->classTeacher($staff, $offering);
        $enrollment = $this->enroll($student, $offering);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);

        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2026-08-27',
            'status' => AttendanceStatus::Present,
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
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'subject_id' => $subject->id,
            'total' => 82,
        ]);

        TermSummary::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'average' => 82,
            'class_position' => 5,
            'class_size' => 32,
        ]);

        Announcement::query()->create([
            'title' => 'House assembly',
            'body' => 'The hall at two.',
            'audience' => AnnouncementAudience::Students,
            'status' => AnnouncementStatus::Published,
            'published_at' => now(),
            'created_by' => $teacher->id,
        ]);

        $this->actingAs($pupil)
            ->getJson('/api/v1/student-desk')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Amaka')
            ->assertJsonPath('data.school', 'Supreme Reagan Schools')
            ->assertJsonPath('data.school_short', 'Supreme Reagan')
            ->assertJsonPath('data.session', '2025/2026')
            ->assertJsonPath('data.term', 'First Term')
            ->assertJsonPath('data.form', $offering->classSection->name)
            ->assertJsonPath('data.campus', $offering->campus->name)
            ->assertJsonPath('data.class_teacher', 'Daniel Okoro')
            ->assertJsonPath('data.admission_number', 'SRS/2025/0412')
            ->assertJsonPath('data.metrics.average_percent', 82)
            ->assertJsonPath('data.metrics.average_delta', 'Good performance')
            ->assertJsonPath('data.metrics.attendance_percent', 100)
            ->assertJsonPath('data.metrics.class_position', 5)
            ->assertJsonPath('data.metrics.class_position_label', '5th')
            ->assertJsonPath('data.metrics.class_size', 32)
            ->assertJsonPath('data.metrics.position_delta', 'Out of 32 students')
            ->assertJsonPath('data.metrics.assignments', 1)
            ->assertJsonPath('data.schedule.0.subject', 'Mathematics')
            ->assertJsonPath('data.schedule.0.status', 'now')
            ->assertJsonPath('data.schedule.0.teacher', 'Daniel Okoro')
            ->assertJsonPath('data.assignments.0.title', 'Algebra sheet')
            ->assertJsonPath('data.notices.0.title', 'House assembly');

        Carbon::setTestNow();
    }

    public function test_admin_staff_and_parent_cannot_read_the_pupil_desk(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/student-desk')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($teacher);
        $this->actingAs($teacher)
            ->getJson('/api/v1/student-desk')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->userWithRole(RoleSlug::Parent))
            ->getJson('/api/v1/student-desk')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_guest_cannot_read_the_pupil_desk(): void
    {
        $this->getJson('/api/v1/student-desk')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
