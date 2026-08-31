<?php

namespace Tests\Feature\Assessments;

use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AssessmentBulkTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_bulk_save_records_the_grid_and_recalculates_totals(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $first = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $offering);
        $second = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $offering);

        $this->actingAs($admin)->postJson('/api/v1/grades/bulk', [
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'scores' => [
                ['enrollment_id' => $first->id, 'score' => 14],
                ['enrollment_id' => $second->id, 'score' => 11],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseCount('assessment_scores', 2);
        $this->assertDatabaseHas('term_results', [
            'enrollment_id' => $first->id,
            'ca_total' => 14,
            'total' => 14,
        ]);
    }

    public function test_bulk_rejects_a_pupil_from_another_class_and_writes_nothing(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $home = $this->offering(session: $session);
        $other = $this->otherOffering($home);
        $subject = $this->subject();
        $this->subjectOffering($home, $subject);
        $own = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $home);
        $stranger = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $other);

        $this->actingAs($admin)->postJson('/api/v1/grades/bulk', [
            'class_section_offering_id' => $home->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'scores' => [
                ['enrollment_id' => $own->id, 'score' => 10],
                ['enrollment_id' => $stranger->id, 'score' => 10],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('assessment_scores', 0);
        $this->assertDatabaseCount('term_results', 0);
    }

    public function test_bulk_over_max_score_rolls_back_the_whole_grid(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $first = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $offering);
        $second = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $offering);

        $this->actingAs($admin)->postJson('/api/v1/grades/bulk', [
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['examination']->id,
            'term_id' => $term->id,
            'scores' => [
                ['enrollment_id' => $first->id, 'score' => 60],
                ['enrollment_id' => $second->id, 'score' => 80],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('assessment_scores', 0);
    }

    public function test_register_returns_the_class_grid_and_final_result_is_read_only(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($admin)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 14,
        ])->assertCreated();

        $this->actingAs($admin)->getJson('/api/v1/grades/register?class_section_offering_id='.$offering->id
            .'&subject_id='.$subject->id
            .'&term_id='.$term->id
            .'&assessment_type_id='.$catalogue['types']['first_ca']->id)
            ->assertOk()
            ->assertJsonPath('data.can_enter', true)
            ->assertJsonPath('data.students.0.score', 14)
            ->assertJsonPath('data.max_score', 15);

        $this->actingAs($admin)->getJson('/api/v1/grades/register?class_section_offering_id='.$offering->id
            .'&subject_id='.$subject->id
            .'&term_id='.$term->id
            .'&view=results')
            ->assertOk()
            ->assertJsonPath('data.view', 'results')
            ->assertJsonPath('data.can_enter', false)
            ->assertJsonPath('data.students.0.total', 14);
    }

    public function test_bulk_save_uses_the_class_session_even_if_another_year_is_sent(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $home = $this->academicSession([
            'name' => '2026/2027',
            'starts_on' => '2026-09-08',
            'ends_on' => '2027-07-24',
            'status' => SessionStatus::Active,
        ]);
        $homeTerm = $this->termFor($home, ['name' => 'First Term', 'status' => SessionStatus::Active]);
        $other = $this->academicSession([
            'name' => '2025/2026',
            'status' => SessionStatus::Planned,
        ]);
        $this->termFor($other, ['name' => 'First Term']);
        $this->settings([
            'current_academic_session_id' => $other->id,
        ]);

        $offering = $this->offering(session: $home);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($admin)->postJson('/api/v1/grades/bulk', [
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'academic_session_id' => $other->id,
            'scores' => [
                ['enrollment_id' => $enrollment->id, 'score' => 10],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('assessment_scores', [
            'enrollment_id' => $enrollment->id,
            'term_id' => $homeTerm->id,
            'score' => 10,
        ]);
    }
}
