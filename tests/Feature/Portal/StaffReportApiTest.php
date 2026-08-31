<?php

namespace Tests\Feature\Portal;

use App\Enums\AttendanceStatus;
use App\Enums\RoleSlug;
use App\Models\AttendanceRecord;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class StaffReportApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_reports_page_has_live_hooks(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($teacher)
            ->get('/staff/reports')
            ->assertOk()
            ->assertSee('faculty-house', false)
            ->assertSee('data-page="reports"', false)
            ->assertSee('data-generate-report', false)
            ->assertSee('data-export-report', false)
            ->assertSee('portal-staff-reports.js', false)
            ->assertSee('href="/staff/reports"', false)
            ->assertDontSee('Mrs. Okafor', false);

        $js = (string) file_get_contents(public_path('site/JS/portal-staff-reports.js'));
        $this->assertStringContainsString('/api/v1/staff-reports', $js);
        $this->assertStringContainsString('staff-reports/export', $js);
    }

    public function test_teacher_can_generate_and_export_a_class_list(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Mrs. Eze']);
        $staff = $this->staff($teacher);
        $offering = $this->offering();
        $this->classTeacher($staff, $offering);
        $this->enroll($this->student($this->userWithRole(RoleSlug::Student, ['name' => 'Chiamaka Okafor'])), $offering);

        $this->actingAs($teacher)
            ->getJson('/api/v1/staff-reports')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.offerings.0.form', 'JSS 1 A')
            ->assertJsonPath('data.kinds.0.slug', 'roll');

        $this->actingAs($teacher)
            ->getJson('/api/v1/staff-reports/generate?kind=roll&class_section_offering_id='.$offering->id)
            ->assertOk()
            ->assertJsonPath('data.kind', 'roll')
            ->assertJsonPath('data.rows.0.full_name', 'Okafor Chiamaka')
            ->assertJsonPath('data.summary.pupils', 1)
            ->assertJsonPath('data.filename', 'class-list-jss-1-a.csv');

        $csv = $this->actingAs($teacher)
            ->get('/api/v1/staff-reports/export?kind=roll&class_section_offering_id='.$offering->id)
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Pupil', $csv);
        $this->assertStringContainsString('Okafor Chiamaka', $csv);
    }

    public function test_teacher_can_export_attendance_and_marks_for_their_form(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $staff = $this->staff($teacher);
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $this->classTeacher($staff, $offering);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $student = $this->student();
        $enrollment = $this->enroll($student, $offering);
        $this->settings([
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);

        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2025-09-09',
            'status' => AttendanceStatus::Present,
            'marked_by' => $teacher->id,
        ]);

        $this->actingAs($teacher)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 14,
        ])->assertCreated();

        $this->actingAs($teacher)
            ->getJson('/api/v1/staff-reports/generate?kind=attendance&class_section_offering_id='.$offering->id.'&from=2025-09-08&to=2025-09-12')
            ->assertOk()
            ->assertJsonPath('data.rows.0.present', 1)
            ->assertJsonPath('data.summary.percent', 100);

        $this->actingAs($teacher)
            ->getJson('/api/v1/staff-reports/generate?kind=marks&class_section_offering_id='.$offering->id.'&subject_id='.$subject->id.'&assessment_type_id='.$catalogue['types']['first_ca']->id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.rows.0.score', 14);

        $this->actingAs($teacher)
            ->getJson('/api/v1/staff-reports/generate?kind=results&class_section_offering_id='.$offering->id.'&subject_id='.$subject->id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.rows.0.total', 14);
    }

    public function test_teacher_cannot_export_another_teachers_form(): void
    {
        $home = $this->offering();
        $other = $this->otherOffering($home);
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacher), $home);

        $this->actingAs($teacher)
            ->getJson('/api/v1/staff-reports/generate?kind=roll&class_section_offering_id='.$other->id)
            ->assertForbidden();
    }

    public function test_admin_cannot_read_faculty_reports(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/staff-reports')
            ->assertForbidden();
    }
}
