<?php

namespace Tests\Feature\People;

use App\Enums\RoleSlug;
use App\Enums\StaffStatus;
use App\Enums\UserStatus;
use App\Models\ClassTeacherAssignment;
use App\Models\StaffProfile;
use App\Models\SubjectTeacherAssignment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class StaffApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_and_update_staff(): void
    {
        $department = $this->department();
        $admin = $this->admin();

        $create = $this->actingAs($admin)->postJson('/api/v1/staff', [
            'name' => 'Mrs. Eze',
            'email' => 'eze@school.test',
            'password' => 'password',
            'role' => RoleSlug::Teacher->value,
            'staff_number' => 'SRS/TCH/0012',
            'department_id' => $department->id,
            'job_title' => 'Class Teacher',
            'phone' => '08030000012',
            'status' => StaffStatus::Active->value,
        ]);

        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.staff_number', 'SRS/TCH/0012')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.account_status', 'active')
            ->assertJsonMissingPath('data.password');

        $id = $create->json('data.id');
        $userId = StaffProfile::query()->findOrFail($id)->user_id;

        $this->assertTrue(Hash::check('password', StaffProfile::query()->findOrFail($id)->user->getAuthPassword()));

        $this->actingAs($admin)->putJson('/api/v1/staff/'.$id, [
            'job_title' => 'Head of Mathematics',
            'status' => StaffStatus::OnLeave->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.job_title', 'Head of Mathematics')
            ->assertJsonPath('data.status', 'on_leave');

        $this->assertDatabaseHas('users', ['id' => $userId, 'email' => 'eze@school.test']);
    }

    public function test_admin_can_appoint_staff_to_a_department_and_form(): void
    {
        $department = $this->department(['name' => 'Sciences']);
        $offering = $this->offering();
        $admin = $this->admin();

        $create = $this->actingAs($admin)->postJson('/api/v1/staff', [
            'name' => 'Mrs. Cynthia Obi',
            'email' => 'obi@school.test',
            'password' => 'password',
            'role' => RoleSlug::Teacher->value,
            'department_id' => $department->id,
            'class_section_offering_id' => $offering->id,
            'job_title' => 'Class Teacher',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.department', 'Sciences')
            ->assertJsonPath('data.forms.0', 'JSS 1 A')
            ->assertJsonPath('data.class_section_offering_id', $offering->id);

        $id = $create->json('data.id');
        $this->assertDatabaseHas('class_teacher_assignments', [
            'staff_profile_id' => $id,
            'class_section_offering_id' => $offering->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson('/api/v1/staff/'.$id, [
            'class_section_offering_id' => $offering->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.class_section_offering_id', $offering->id);

        $this->assertSame(1, ClassTeacherAssignment::query()->where('staff_profile_id', $id)->count());
    }

    public function test_admin_can_change_and_clear_a_class_teacher_form(): void
    {
        $admin = $this->admin();
        $home = $this->offering();
        $next = $this->otherOffering($home);
        $create = $this->actingAs($admin)->postJson('/api/v1/staff', [
            'name' => 'Mr. Daniel Okoro',
            'email' => 'okoro@school.test',
            'password' => 'password',
            'role' => RoleSlug::Teacher->value,
            'class_section_offering_id' => $home->id,
        ])->assertCreated();

        $id = $create->json('data.id');

        $this->actingAs($admin)->putJson('/api/v1/staff/'.$id, [
            'class_section_offering_id' => $next->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.class_section_offering_id', $next->id)
            ->assertJsonCount(1, 'data.forms')
            ->assertJsonPath('data.forms.0', 'JSS 2 B');

        $this->assertDatabaseHas('class_teacher_assignments', [
            'staff_profile_id' => $id,
            'class_section_offering_id' => $home->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('class_teacher_assignments', [
            'staff_profile_id' => $id,
            'class_section_offering_id' => $next->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson('/api/v1/staff/'.$id, [
            'class_section_offering_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.class_section_offering_id', null)
            ->assertJsonPath('data.forms', []);

        $this->assertSame(0, ClassTeacherAssignment::query()
            ->where('staff_profile_id', $id)
            ->where('is_active', true)
            ->count());
    }

    public function test_duplicate_staff_number_is_rejected(): void
    {
        $admin = $this->admin();
        $this->staff($this->userWithRole(RoleSlug::Teacher), ['staff_number' => 'SRS/TCH/0012']);

        $this->actingAs($admin)->postJson('/api/v1/staff', [
            'name' => 'Other Teacher',
            'email' => 'other@school.test',
            'password' => 'password',
            'staff_number' => 'SRS/TCH/0012',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('staff_number');
    }

    public function test_invalid_department_is_rejected(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/staff', [
            'name' => 'Mrs. Eze',
            'email' => 'eze@school.test',
            'password' => 'password',
            'department_id' => 9999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_staff_cannot_be_created_with_a_student_role(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/staff', [
            'name' => 'Not A Teacher',
            'email' => 'student-role@school.test',
            'password' => 'password',
            'role' => RoleSlug::Student->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_teacher_cannot_create_staff(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($teacher);

        $this->actingAs($teacher)->postJson('/api/v1/staff', [
            'name' => 'Hacked',
            'email' => 'hacked@school.test',
            'password' => 'password',
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_admin_can_suspend_and_reinstate_staff(): void
    {
        $admin = $this->admin();
        $user = $this->userWithRole(RoleSlug::Teacher, [
            'email' => 'master@school.test',
            'password' => 'secret-pass',
        ]);
        $staff = $this->staff($user);
        $offering = $this->offering();
        $assignment = $this->classTeacher($staff, $offering);

        $this->actingAs($admin)
            ->postJson('/api/v1/staff/'.$staff->id.'/suspend')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Staff suspended.')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.account_status', 'suspended');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => UserStatus::Suspended->value,
        ]);
        $this->assertDatabaseHas('staff_profiles', [
            'id' => $staff->id,
            'status' => StaffStatus::Inactive->value,
        ]);
        $this->assertDatabaseHas('class_teacher_assignments', [
            'id' => $assignment->id,
            'is_active' => true,
        ]);

        $this->post('/logout');
        $this->assertGuest();

        $this->from('/staff/login')->post('/login', [
            'email' => 'master@school.test',
            'password' => 'secret-pass',
            'portal' => 'staff',
        ])
            ->assertRedirect('/staff/login')
            ->assertSessionHasErrors('email');

        $this->actingAs($admin)
            ->postJson('/api/v1/staff/'.$staff->id.'/reinstate')
            ->assertOk()
            ->assertJsonPath('message', 'Staff reinstated.')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.account_status', 'active');
    }

    public function test_admin_can_remove_staff_with_live_assignments(): void
    {
        $admin = $this->admin();
        $staff = $this->staff($this->userWithRole(RoleSlug::Teacher, ['email' => 'leave@school.test']));
        $offering = $this->offering();
        $classAssignment = $this->classTeacher($staff, $offering);
        $subjectAssignment = $this->subjectTeacher($staff, $this->subjectOffering($offering));

        $this->actingAs($admin)
            ->getJson('/api/v1/staff')
            ->assertOk()
            ->assertJsonPath('data.0.forms.0', 'JSS 1 A')
            ->assertJsonPath('data.0.subjects.0', 'English Language')
            ->assertJsonPath('data.0.account_status', 'active');

        $this->actingAs($admin)
            ->deleteJson('/api/v1/staff/'.$staff->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Staff removed.');

        $this->assertSoftDeleted('staff_profiles', ['id' => $staff->id]);
        $this->assertDatabaseHas('users', [
            'id' => $staff->user_id,
            'status' => UserStatus::Inactive->value,
        ]);

        $classAssignment = ClassTeacherAssignment::query()->findOrFail($classAssignment->id);
        $this->assertFalse($classAssignment->is_active);
        $this->assertNotNull($classAssignment->ended_on);

        $subjectAssignment = SubjectTeacherAssignment::query()->findOrFail($subjectAssignment->id);
        $this->assertFalse($subjectAssignment->is_active);
        $this->assertNotNull($subjectAssignment->ended_on);
    }
}
