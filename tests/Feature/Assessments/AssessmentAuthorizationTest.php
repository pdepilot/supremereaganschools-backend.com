<?php

namespace Tests\Feature\Assessments;

use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AssessmentAuthorizationTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_class_teacher_can_enter_scores_for_any_offered_subject(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacherUser), $offering);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($teacherUser)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 12,
        ])->assertCreated();
    }

    public function test_subject_teacher_can_enter_assigned_subject_but_not_another(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $maths = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $science = $this->subject(['name' => 'Basic Science', 'code' => 'BSC']);
        $mathsOffering = $this->subjectOffering($offering, $maths);
        $this->subjectOffering($offering, $science);
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $this->subjectTeacher($this->staff($teacherUser), $mathsOffering);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($teacherUser)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $maths->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 10,
        ])->assertCreated();

        $this->actingAs($teacherUser)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $science->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 10,
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');

        $this->actingAs($teacherUser)->getJson('/api/v1/grades/register?class_section_offering_id='.$offering->id
            .'&subject_id='.$science->id
            .'&term_id='.$term->id
            .'&assessment_type_id='.$catalogue['types']['first_ca']->id)
            ->assertForbidden();
    }

    public function test_unrelated_teacher_cannot_enter_or_open_the_register(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $home = $this->offering(session: $session);
        $other = $this->otherOffering($home);
        $subject = $this->subject();
        $this->subjectOffering($home, $subject);
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacherUser), $other);
        $enrollment = $this->enroll($this->student(), $home);

        $this->actingAs($teacherUser)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 10,
        ])->assertForbidden();

        $this->actingAs($teacherUser)->getJson('/api/v1/grades/register?class_section_offering_id='.$home->id
            .'&subject_id='.$subject->id
            .'&term_id='.$term->id
            .'&assessment_type_id='.$catalogue['types']['first_ca']->id)
            ->assertForbidden();
    }

    public function test_staff_without_an_assignment_cannot_enter_scores(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($teacherUser);
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($teacherUser)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 10,
        ])->assertForbidden();
    }

    public function test_parent_can_view_own_child_results_but_not_another_child(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $childA = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $childB = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']);
        $enrollmentA = $this->enroll($childA, $offering);
        $enrollmentB = $this->enroll($childB, $offering);
        $parentA = $this->userWithRole(RoleSlug::Parent);
        $this->linkGuardian($this->guardian($parentA, ['email' => $parentA->email]), $childA);

        $this->actingAs($admin)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollmentA->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['examination']->id,
            'term_id' => $term->id,
            'score' => 60,
        ])->assertCreated();

        $this->actingAs($parentA)->getJson('/api/v1/results?student_profile_id='.$childA->id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.student_profile_id', $childA->id);

        $this->actingAs($parentA)->getJson('/api/v1/results?student_profile_id='.$childB->id.'&term_id='.$term->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');

        $this->actingAs($parentA)->getJson('/api/v1/results/summary?student_profile_id='.$childB->id.'&term_id='.$term->id)
            ->assertForbidden();

        $this->actingAs($parentA)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollmentA->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 10,
        ])->assertForbidden();
    }

    public function test_student_can_view_own_results_but_not_another_students(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $studentA = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $studentB = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']);
        $this->actingAs($admin)->postJson('/api/v1/grades', [
            'enrollment_id' => $this->enroll($studentA, $offering)->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['examination']->id,
            'term_id' => $term->id,
            'score' => 55,
        ])->assertCreated();
        $this->enroll($studentB, $offering);

        $this->actingAs($studentA->user)->getJson('/api/v1/results?term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.student_profile_id', $studentA->id);

        $this->actingAs($studentA->user)->getJson('/api/v1/results?student_profile_id='.$studentB->id.'&term_id='.$term->id)
            ->assertForbidden();
    }

    public function test_staff_cannot_change_a_sealed_score_but_admin_can(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session, ['status' => SessionStatus::Active]);
        $offering = $this->offering(session: $session);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacherUser), $offering);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($teacherUser)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 12,
        ])->assertCreated();

        $this->actingAs($teacherUser)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 14,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('score');

        $this->actingAs($teacherUser)->getJson('/api/v1/grades/register?class_section_offering_id='.$offering->id
            .'&subject_id='.$subject->id
            .'&term_id='.$term->id
            .'&assessment_type_id='.$catalogue['types']['first_ca']->id)
            ->assertOk()
            ->assertJsonPath('data.students.0.score', 12)
            ->assertJsonPath('data.students.0.recorded', true)
            ->assertJsonPath('data.students.0.can_enter', false)
            ->assertJsonPath('data.can_amend', false);

        $this->actingAs($this->admin())->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 14,
        ])->assertCreated()->assertJsonPath('data.score', 14);

        $this->actingAs($this->admin())->getJson('/api/v1/results?student_profile_id='.$enrollment->student_profile_id.'&term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.can_amend', true)
            ->assertJsonPath('data.results.0.scores.0.score', 14);
    }

    public function test_staff_can_still_fill_an_unrecorded_cell_after_another_is_sealed(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session);
        $offering = $this->offering(session: $session);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacherUser), $offering);
        $first = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $offering);
        $second = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $offering);

        $this->actingAs($teacherUser)->postJson('/api/v1/grades', [
            'enrollment_id' => $first->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 10,
        ])->assertCreated();

        $this->actingAs($teacherUser)->postJson('/api/v1/grades/bulk', [
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'scores' => [
                ['enrollment_id' => $first->id, 'score' => 15],
                ['enrollment_id' => $second->id, 'score' => 11],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('scores.0.score');

        $this->actingAs($teacherUser)->postJson('/api/v1/grades/bulk', [
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'scores' => [
                ['enrollment_id' => $second->id, 'score' => 11],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('assessment_scores', [
            'enrollment_id' => $first->id,
            'score' => 10,
        ]);
        $this->assertDatabaseHas('assessment_scores', [
            'enrollment_id' => $second->id,
            'score' => 11,
        ]);
    }
}
