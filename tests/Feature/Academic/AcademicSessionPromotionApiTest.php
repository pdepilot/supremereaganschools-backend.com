<?php

namespace Tests\Feature\Academic;

use App\Enums\EnrollmentStatus;
use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use App\Models\AssessmentScore;
use App\Models\ClassSectionOffering;
use App\Models\Enrollment;
use App\Models\SubjectOffering;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AcademicSessionPromotionApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_copy_forms_teachers_and_pupils_into_the_next_year(): void
    {
        $admin = $this->admin();
        $source = $this->academicSession([
            'name' => '2025/2026',
            'status' => SessionStatus::Archived,
        ]);
        $target = $this->academicSession([
            'name' => '2026/2027',
            'starts_on' => '2026-09-08',
            'ends_on' => '2027-07-24',
            'status' => SessionStatus::Planned,
        ]);
        $this->termFor($target, ['name' => 'First Term', 'status' => SessionStatus::Planned]);

        $offering = $this->offering(session: $source);
        $maths = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $subjectOffering = $this->subjectOffering($offering, $maths);
        $teacher = $this->staff($this->userWithRole(RoleSlug::Teacher));
        $this->classTeacher($teacher, $offering);
        $this->subjectTeacher($teacher, $subjectOffering);
        $pupil = $this->student();
        $oldEnrollment = $this->enroll($pupil, $offering);

        $this->actingAs($admin)->postJson('/api/v1/academic-sessions/'.$target->id.'/promote', [
            'source_academic_session_id' => $source->id,
            'copy_teachers' => true,
            'enroll_pupils' => true,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.offerings_created', 1)
            ->assertJsonPath('data.enrollments_created', 1)
            ->assertJsonPath('data.class_teachers_copied', 1)
            ->assertJsonPath('data.subject_teachers_copied', 1);

        $this->assertDatabaseHas('class_section_offerings', [
            'class_section_id' => $offering->class_section_id,
            'academic_session_id' => $target->id,
            'is_active' => true,
        ]);

        $newOffering = ClassSectionOffering::query()
            ->where('class_section_id', $offering->class_section_id)
            ->where('academic_session_id', $target->id)
            ->firstOrFail();

        $this->assertDatabaseHas('subject_offerings', [
            'class_section_offering_id' => $newOffering->id,
            'subject_id' => $maths->id,
        ]);
        $this->assertDatabaseHas('class_teacher_assignments', [
            'class_section_offering_id' => $newOffering->id,
            'staff_profile_id' => $teacher->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'student_profile_id' => $pupil->id,
            'academic_session_id' => $target->id,
            'class_section_offering_id' => $newOffering->id,
            'status' => EnrollmentStatus::Active->value,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'id' => $oldEnrollment->id,
            'status' => EnrollmentStatus::Completed->value,
        ]);
    }

    public function test_promotion_is_idempotent_and_moves_marks_saved_on_the_live_term(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $source = $this->academicSession([
            'name' => '2025/2026',
            'status' => SessionStatus::Archived,
        ]);
        $target = $this->academicSession([
            'name' => '2026/2027',
            'starts_on' => '2026-09-08',
            'ends_on' => '2027-07-24',
            'status' => SessionStatus::Active,
        ]);
        $liveTerm = $this->termFor($target, [
            'name' => 'First Term',
            'status' => SessionStatus::Active,
        ]);

        $offering = $this->offering(session: $source);
        $maths = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $maths);
        $pupil = $this->student();
        $oldEnrollment = $this->enroll($pupil, $offering);

        AssessmentScore::query()->create([
            'enrollment_id' => $oldEnrollment->id,
            'term_id' => $liveTerm->id,
            'subject_id' => $maths->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'score' => 12,
            'entered_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/academic-sessions/'.$target->id.'/promote', [
            'source_academic_session_id' => $source->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.enrollments_created', 1)
            ->assertJsonPath('data.marks_moved', 1);

        $newEnrollment = Enrollment::query()
            ->where('student_profile_id', $pupil->id)
            ->where('academic_session_id', $target->id)
            ->firstOrFail();

        $this->assertDatabaseHas('assessment_scores', [
            'enrollment_id' => $newEnrollment->id,
            'term_id' => $liveTerm->id,
            'score' => 12,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/academic-sessions/'.$target->id.'/promote', [
            'source_academic_session_id' => $source->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.offerings_created', 0)
            ->assertJsonPath('data.offerings_existing', 1)
            ->assertJsonPath('data.enrollments_created', 0)
            ->assertJsonPath('data.enrollments_skipped', 1);

        $this->assertSame(1, ClassSectionOffering::query()->where('academic_session_id', $target->id)->count());
        $this->assertSame(1, Enrollment::query()->where('academic_session_id', $target->id)->count());
        $this->assertSame(1, SubjectOffering::query()->where('class_section_offering_id', $newEnrollment->class_section_offering_id)->count());
    }

    public function test_promotion_rejects_the_same_year_and_an_archived_target(): void
    {
        $admin = $this->admin();
        $source = $this->academicSession(['name' => '2025/2026', 'status' => SessionStatus::Archived]);
        $target = $this->academicSession([
            'name' => '2024/2025',
            'starts_on' => '2024-09-09',
            'ends_on' => '2025-07-25',
            'status' => SessionStatus::Archived,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/academic-sessions/'.$source->id.'/promote', [
            'source_academic_session_id' => $source->id,
        ])->assertUnprocessable();

        $this->actingAs($admin)->postJson('/api/v1/academic-sessions/'.$target->id.'/promote', [
            'source_academic_session_id' => $source->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('academic_session');
    }

    public function test_teacher_cannot_promote_a_session(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($teacher);
        $source = $this->academicSession(['name' => '2025/2026']);
        $target = $this->academicSession([
            'name' => '2026/2027',
            'starts_on' => '2026-09-08',
            'ends_on' => '2027-07-24',
        ]);

        $this->actingAs($teacher)->postJson('/api/v1/academic-sessions/'.$target->id.'/promote', [
            'source_academic_session_id' => $source->id,
        ])->assertForbidden();
    }
}
