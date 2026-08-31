<?php

namespace Tests\Feature\Portal;

use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalGradesPageTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_marks_page_loads_with_backend_hooks(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/grades')
            ->assertOk()
            ->assertSee('data-page="grades"', false)
            ->assertSee('gradesBody', false)
            ->assertSee('data-admin-results', false)
            ->assertSee('data-pupil-results-body', false)
            ->assertSee('data-pupil-results-save', false)
            ->assertSee('data-report-comments', false)
            ->assertSee('data-print-report-sheet', false)
            ->assertSee('portal-report-sheet.js', false)
            ->assertSee('portal-grades.js', false)
            ->assertSee('href="/portal/grades"', false)
            ->assertDontSee('91%', false)
            ->assertDontSee('Mrs. Okafor', false);

        $gradesJs = (string) file_get_contents(public_path('site/JS/portal-grades.js'));
        $this->assertStringContainsString('syncSessionToClass', $gradesJs);
        $this->assertStringContainsString('/api/v1/results/comments', $gradesJs);
        $reportJs = (string) file_get_contents(public_path('site/JS/portal-report-sheet.js'));
        $this->assertStringContainsString('Terminal report sheet', $reportJs);
        $this->assertStringContainsString('term-report-head', $reportJs);
    }

    public function test_super_admin_can_read_recorded_pupil_results(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $super = $this->userWithRole(RoleSlug::SuperAdmin);
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session, ['status' => SessionStatus::Active]);
        $offering = $this->offering(session: $session);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $pupil = $this->student();
        $enrollment = $this->enroll($pupil, $offering);

        $this->actingAs($this->admin())->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['examination']->id,
            'term_id' => $term->id,
            'score' => 64,
        ])->assertCreated();

        $this->actingAs($super)
            ->getJson('/api/v1/grades/register?class_section_offering_id='.$offering->id
                .'&subject_id='.$subject->id
                .'&term_id='.$term->id
                .'&assessment_type_id='.$catalogue['types']['examination']->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.can_enter', true)
            ->assertJsonPath('data.students.0.score', 64);

        $this->actingAs($super)
            ->getJson('/api/v1/results?student_profile_id='.$pupil->id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.student_profile_id', $pupil->id)
            ->assertJsonPath('data.results.0.exam_score', 64)
            ->assertJsonPath('data.results.0.total', 64)
            ->assertJsonPath('data.can_amend', true)
            ->assertJsonPath('data.results.0.scores.2.score', 64);
    }

    public function test_teacher_cannot_open_admin_marks_page(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($teacher)
            ->get('/portal/grades')
            ->assertRedirect(route('staff.home'));
    }
}
