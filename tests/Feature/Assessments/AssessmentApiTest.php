<?php

namespace Tests\Feature\Assessments;

use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use App\Models\AssessmentScore;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AssessmentApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_catalogue_lists_types_and_scales(): void
    {
        $this->assessmentCatalogue();
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/v1/assessment-types')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.kind', 'first_ca')
            ->assertJsonPath('data.0.max_score', 15);

        $this->actingAs($admin)->getJson('/api/v1/grade-scales')
            ->assertOk()
            ->assertJsonPath('data.0.grade', 'A')
            ->assertJsonPath('data.0.remark', 'Excellent');
    }

    public function test_admin_can_record_a_score_within_the_type_maximum(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session, ['status' => SessionStatus::Active]);
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
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.score', 14)
            ->assertJsonPath('data.entered_by', $admin->name);

        $this->assertDatabaseHas('term_results', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'ca_total' => 14,
            'exam_score' => 0,
            'total' => 14,
        ]);
    }

    public function test_score_above_the_type_maximum_is_rejected(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($admin)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 16,
        ])->assertUnprocessable()->assertJsonValidationErrors('score');
    }

    public function test_duplicate_score_cells_are_unique_at_the_database(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $enrollment = $this->enroll($this->student(), $offering);

        AssessmentScore::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'score' => 10,
            'entered_by' => $admin->id,
        ]);

        $this->expectException(QueryException::class);

        AssessmentScore::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'score' => 12,
            'entered_by' => $admin->id,
        ]);
    }

    public function test_totals_grade_and_remark_follow_the_15_15_70_scale(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session, ['status' => SessionStatus::Active]);
        $offering = $this->offering(session: $session);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $enrollment = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $offering);

        foreach ([
            ['first_ca', 14],
            ['second_ca', 14],
            ['examination', 66],
        ] as [$kind, $score]) {
            $this->actingAs($admin)->postJson('/api/v1/grades', [
                'enrollment_id' => $enrollment->id,
                'subject_id' => $subject->id,
                'assessment_type_id' => $catalogue['types'][$kind]->id,
                'term_id' => $term->id,
                'score' => $score,
            ])->assertCreated();
        }

        $this->assertDatabaseHas('term_results', [
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'subject_id' => $subject->id,
            'ca_total' => 28,
            'exam_score' => 66,
            'total' => 94,
            'grade' => 'A',
            'remark' => 'Excellent',
        ]);

        $this->actingAs($admin)->getJson('/api/v1/results?student_profile_id='.$enrollment->student_profile_id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.results.0.total', 94)
            ->assertJsonPath('data.results.0.grade', 'A')
            ->assertJsonPath('data.average', 94)
            ->assertJsonPath('data.class_position', 1);
    }

    public function test_class_position_uses_competition_ranking(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $maths = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $maths);
        $first = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $offering);
        $second = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $offering);
        $third = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0221']), $offering);

        foreach ([[$first, 70], [$second, 70], [$third, 50]] as [$enrollment, $exam]) {
            $this->actingAs($admin)->postJson('/api/v1/grades', [
                'enrollment_id' => $enrollment->id,
                'subject_id' => $maths->id,
                'assessment_type_id' => $catalogue['types']['examination']->id,
                'term_id' => $term->id,
                'score' => $exam,
            ])->assertCreated();
        }

        $this->actingAs($admin)->getJson('/api/v1/results/summary?student_profile_id='.$first->student_profile_id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.class_position', 1)
            ->assertJsonPath('data.class_size', 3);

        $this->actingAs($admin)->getJson('/api/v1/results/summary?student_profile_id='.$second->student_profile_id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.class_position', 1);

        $this->actingAs($admin)->getJson('/api/v1/results/summary?student_profile_id='.$third->student_profile_id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.class_position', 3);
    }

    public function test_entered_by_cannot_be_mass_assigned_from_the_request(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $stranger = $this->userWithRole(RoleSlug::Teacher);
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($admin)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 12,
            'entered_by' => $stranger->id,
        ])->assertCreated()->assertJsonPath('data.entered_by', $admin->name);

        $this->assertDatabaseHas('assessment_scores', [
            'enrollment_id' => $enrollment->id,
            'entered_by' => $admin->id,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/grades/contexts')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_archived_sessions_reject_score_writes(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Archived]);
        $term = $this->termFor($session, ['status' => SessionStatus::Archived]);
        $offering = $this->offering(session: $session);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($admin)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('term_id');
    }

    public function test_score_for_a_subject_not_offered_in_the_class_is_rejected(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $offered = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $other = $this->subject(['name' => 'French', 'code' => 'FRE']);
        $this->subjectOffering($offering, $offered);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($admin)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $other->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    }
}
