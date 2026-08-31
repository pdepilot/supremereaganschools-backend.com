<?php

namespace Tests\Feature\People;

use App\Enums\EnrollmentStatus;
use App\Enums\RoleSlug;
use App\Models\Enrollment;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class EnrollmentApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_enroll_a_student_in_a_valid_form(): void
    {
        $offering = $this->offering();
        $student = $this->student();

        $this->actingAs($this->admin())->postJson('/api/v1/enrollments', [
            'student_profile_id' => $student->id,
            'class_section_id' => $offering->class_section_id,
            'academic_session_id' => $offering->academic_session_id,
            'school_class_id' => $offering->classSection->school_class_id,
            'status' => EnrollmentStatus::Active->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.class_section_offering_id', $offering->id);
    }

    public function test_invalid_section_class_combination_is_rejected(): void
    {
        $first = $this->schoolClass(null, ['name' => 'JSS 1', 'short_code' => 'J1']);
        $second = $this->schoolClass($first->level, ['name' => 'JSS 2', 'short_code' => 'J2']);
        $section = $this->section($first, ['arm' => 'A', 'name' => 'JSS 1 A']);
        $session = $this->academicSession();
        $this->offering($section, $session);

        $this->actingAs($this->admin())->postJson('/api/v1/enrollments', [
            'student_profile_id' => $this->student()->id,
            'class_section_id' => $section->id,
            'academic_session_id' => $session->id,
            'school_class_id' => $second->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('class_section_id');
    }

    public function test_duplicate_enrollment_in_the_same_session_is_rejected(): void
    {
        $offering = $this->offering();
        $student = $this->student();
        $this->enroll($student, $offering);

        $this->actingAs($this->admin())->postJson('/api/v1/enrollments', [
            'student_profile_id' => $student->id,
            'class_section_offering_id' => $offering->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('academic_session_id');
    }

    public function test_historical_enrollments_in_different_sessions_are_preserved(): void
    {
        $section = $this->section();
        $campus = $this->campus();
        $firstSession = $this->academicSession(['name' => '2024/2025', 'starts_on' => '2024-09-09', 'ends_on' => '2025-07-25']);
        $secondSession = $this->academicSession(['name' => '2025/2026']);
        $firstOffering = $this->offering($section, $firstSession, $campus);
        $secondOffering = $this->offering($section, $secondSession, $campus);
        $student = $this->student();

        $this->enroll($student, $firstOffering, [
            'status' => EnrollmentStatus::Completed,
            'enrolled_on' => '2024-09-09',
            'left_on' => '2025-07-25',
        ]);

        $this->actingAs($this->admin())->postJson('/api/v1/enrollments', [
            'student_profile_id' => $student->id,
            'class_section_offering_id' => $secondOffering->id,
            'enrolled_on' => '2025-09-08',
        ])->assertCreated();

        $this->assertSame(2, $student->enrollments()->count());
        $this->assertDatabaseHas('enrollments', [
            'student_profile_id' => $student->id,
            'academic_session_id' => $firstSession->id,
            'status' => EnrollmentStatus::Completed->value,
        ]);
    }

    public function test_database_rejects_two_enrollments_for_the_same_student_and_session(): void
    {
        $offering = $this->offering();
        $student = $this->student();
        $this->enroll($student, $offering);

        $this->expectException(QueryException::class);

        Enrollment::query()->create([
            'student_profile_id' => $student->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $offering->academic_session_id,
            'status' => EnrollmentStatus::Completed,
            'enrolled_on' => '2025-09-09',
        ]);
    }

    public function test_teacher_cannot_create_enrollments(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($teacher);
        $offering = $this->offering();

        $this->actingAs($teacher)->postJson('/api/v1/enrollments', [
            'student_profile_id' => $this->student()->id,
            'class_section_offering_id' => $offering->id,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    }
}
