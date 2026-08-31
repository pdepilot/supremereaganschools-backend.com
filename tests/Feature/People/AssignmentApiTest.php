<?php

namespace Tests\Feature\People;

use App\Enums\RoleSlug;
use App\Enums\StaffStatus;
use App\Models\ClassTeacherAssignment;
use App\Models\SubjectTeacherAssignment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AssignmentApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_assign_a_class_teacher_and_preserve_history(): void
    {
        $offering = $this->offering();
        $first = $this->staff($this->userWithRole(RoleSlug::Teacher));
        $second = $this->staff($this->userWithRole(RoleSlug::Teacher), ['staff_number' => 'SRS/TCH/0099']);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/class-teacher-assignments', [
            'staff_profile_id' => $first->id,
            'class_section_offering_id' => $offering->id,
        ])->assertCreated()->assertJsonPath('data.is_active', true);

        $this->actingAs($admin)->postJson('/api/v1/class-teacher-assignments', [
            'staff_profile_id' => $second->id,
            'class_section_offering_id' => $offering->id,
        ])->assertCreated()->assertJsonPath('data.is_active', true);

        $this->assertFalse(ClassTeacherAssignment::query()->where('staff_profile_id', $first->id)->first()?->is_active);
        $this->assertTrue(ClassTeacherAssignment::query()->where('staff_profile_id', $second->id)->first()?->is_active);
        $this->assertSame(2, ClassTeacherAssignment::query()->where('class_section_offering_id', $offering->id)->count());
    }

    public function test_duplicate_active_class_teacher_assignment_is_rejected(): void
    {
        $offering = $this->offering();
        $staff = $this->staff();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/class-teacher-assignments', [
            'staff_profile_id' => $staff->id,
            'class_section_offering_id' => $offering->id,
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/class-teacher-assignments', [
            'staff_profile_id' => $staff->id,
            'class_section_offering_id' => $offering->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('staff_profile_id');
    }

    public function test_inactive_or_non_teaching_staff_cannot_be_assigned(): void
    {
        $offering = $this->offering();
        $inactive = $this->staff($this->userWithRole(RoleSlug::Teacher), ['status' => StaffStatus::Inactive]);
        $accountant = $this->staff($this->userWithRole(RoleSlug::Accountant), ['staff_number' => 'SRS/TCH/0400']);

        $this->actingAs($this->admin())->postJson('/api/v1/class-teacher-assignments', [
            'staff_profile_id' => $inactive->id,
            'class_section_offering_id' => $offering->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_profile_id');

        $this->actingAs($this->admin())->postJson('/api/v1/class-teacher-assignments', [
            'staff_profile_id' => $accountant->id,
            'class_section_offering_id' => $offering->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_profile_id');

        $this->actingAs($this->admin())->postJson('/api/v1/class-teacher-assignments', [
            'staff_profile_id' => 9999,
            'class_section_offering_id' => $offering->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_profile_id');
    }

    public function test_admin_can_assign_a_subject_teacher_to_an_offering(): void
    {
        $subjectOffering = $this->subjectOffering();
        $staff = $this->staff();

        $this->actingAs($this->admin())->postJson('/api/v1/subject-teacher-assignments', [
            'staff_profile_id' => $staff->id,
            'subject_offering_id' => $subjectOffering->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.subject_offering_id', $subjectOffering->id)
            ->assertJsonPath('data.is_active', true);

        $this->actingAs($this->admin())->postJson('/api/v1/subject-teacher-assignments', [
            'staff_profile_id' => $staff->id,
            'subject_offering_id' => $subjectOffering->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_profile_id');
    }

    public function test_invalid_subject_offering_is_rejected_and_history_is_kept_when_ended(): void
    {
        $subjectOffering = $this->subjectOffering();
        $staff = $this->staff();
        $admin = $this->admin();

        $created = $this->actingAs($admin)->postJson('/api/v1/subject-teacher-assignments', [
            'staff_profile_id' => $staff->id,
            'subject_offering_id' => $subjectOffering->id,
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/subject-teacher-assignments', [
            'staff_profile_id' => $staff->id,
            'subject_offering_id' => 9999,
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_offering_id');

        $this->actingAs($admin)
            ->deleteJson('/api/v1/subject-teacher-assignments/'.$created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(1, SubjectTeacherAssignment::query()->count());
    }

    public function test_teacher_cannot_assign_another_teacher(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($teacher);

        $this->actingAs($teacher)->postJson('/api/v1/class-teacher-assignments', [
            'staff_profile_id' => $this->staff($this->userWithRole(RoleSlug::Teacher), ['staff_number' => 'SRS/TCH/0777'])->id,
            'class_section_offering_id' => $this->offering()->id,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    }
}
