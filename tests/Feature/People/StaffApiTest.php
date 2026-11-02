<?php

namespace Tests\Feature\People;

use App\Enums\RoleSlug;
use App\Enums\StaffStatus;
use App\Enums\UserStatus;
use App\Models\ClassTeacherAssignment;
use App\Models\StaffProfile;
use App\Models\SubjectTeacherAssignment;
use App\Models\User;
use App\Support\Phone;
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
        $user = StaffProfile::query()->findOrFail($id)->user;

        $this->assertTrue(Hash::check(Phone::nationalKey('08030000012'), $user->getAuthPassword()));
        $this->assertTrue($user->must_change_password);

        $this->actingAs($admin)->putJson('/api/v1/staff/'.$id, [
            'job_title' => 'Head of Mathematics',
            'status' => StaffStatus::OnLeave->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.job_title', 'Head of Mathematics')
            ->assertJsonPath('data.status', 'on_leave');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'eze@school.test']);
    }

    public function test_staff_create_requires_phone_and_rejects_admin_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/staff', [
            'name' => 'No Phone',
            'email' => 'nophone@school.test',
            'role' => RoleSlug::Teacher->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_new_staff_can_sign_in_with_phone_then_set_passphrase(): void
    {
        $admin = $this->admin();

        $create = $this->actingAs($admin)->postJson('/api/v1/staff', [
            'name' => 'Mrs. Phone Login',
            'email' => 'phone.login@school.test',
            'role' => RoleSlug::Teacher->value,
            'phone' => '08031112233',
        ])->assertCreated();

        $userId = StaffProfile::query()->findOrFail($create->json('data.id'))->user_id;

        $this->post('/logout');
        $this->assertGuest();

        $this->postJson('/login', [
            'email' => '08031112233',
            'password' => '08031112233',
            'portal' => 'staff',
        ])
            ->assertOk()
            ->assertJsonPath('data.redirect', '/staff/settings')
            ->assertJsonPath('data.user.must_change_password', true);

        $this->assertAuthenticated();

        $this->putJson('/api/v1/me/password', [
            'current_password' => '08031112233',
            'password' => 'new-desk-pass',
            'password_confirmation' => 'new-desk-pass',
        ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->assertFalse(User::query()->findOrFail($userId)->must_change_password);
        $this->assertTrue(Hash::check('new-desk-pass', User::query()->findOrFail($userId)->getAuthPassword()));

        $this->post('/logout');

        $this->postJson('/login', [
            'email' => 'phone.login@school.test',
            'password' => 'new-desk-pass',
            'portal' => 'staff',
        ])
            ->assertOk()
            ->assertJsonPath('data.redirect', route('staff.home', absolute: false))
            ->assertJsonPath('data.user.must_change_password', false);
    }

    public function test_admin_can_appoint_staff_to_a_department_and_form(): void
    {
        $department = $this->department(['name' => 'Sciences']);
        $offering = $this->offering();
        $admin = $this->admin();

        $create = $this->actingAs($admin)->postJson('/api/v1/staff', [
            'name' => 'Mrs. Cynthia Obi',
            'email' => 'obi@school.test',
            'role' => RoleSlug::Teacher->value,
            'phone' => '08030000021',
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
            'phone' => '08030000017',
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
            'phone' => '08039999999',
            'staff_number' => 'SRS/TCH/0012',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('staff_number');
    }

    public function test_invalid_department_is_rejected(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/staff', [
            'name' => 'Mrs. Eze',
            'email' => 'eze@school.test',
            'phone' => '08030000012',
            'department_id' => 9999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_staff_cannot_be_created_with_a_student_role(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/staff', [
            'name' => 'Not A Teacher',
            'email' => 'student-role@school.test',
            'phone' => '08030000099',
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
            'phone' => '08030000088',
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
            ->assertJsonPath('data.items.0.forms.0', 'JSS 1 A')
            ->assertJsonPath('data.items.0.subjects.0', 'English Language')
            ->assertJsonPath('data.items.0.account_status', 'active');

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

    public function test_staff_directory_returns_pagination_metadata_and_default_page_size(): void
    {
        $admin = $this->admin();

        for ($i = 1; $i <= 30; $i++) {
            $user = $this->userWithRole(RoleSlug::Teacher, [
                'name' => 'Teacher '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'email' => "teacher{$i}@school.test",
            ]);
            $this->staff($user, [
                'staff_number' => 'SRS/TCH/'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
        }

        $response = $this->actingAs($admin)->getJson('/api/v1/staff');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.per_page', 25)
            ->assertJsonPath('data.meta.last_page', 2)
            ->assertJsonPath('data.meta.from', 1)
            ->assertJsonPath('data.meta.to', 25)
            ->assertJsonPath('data.meta.total', 30)
            ->assertJsonCount(25, 'data.items')
            ->assertJsonPath('data.summary.total', 30)
            ->assertJsonPath('data.summary.active', 30);
    }

    public function test_admin_can_select_supported_page_sizes(): void
    {
        $admin = $this->admin();

        for ($i = 1; $i <= 55; $i++) {
            $user = $this->userWithRole(RoleSlug::Teacher, ['email' => "page_size_{$i}@school.test"]);
            $this->staff($user, [
                'staff_number' => 'SRS/TCH/'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
        }

        foreach ([10, 50] as $size) {
            $response = $this->actingAs($admin)->getJson("/api/v1/staff?per_page={$size}");

            $response->assertOk()
                ->assertJsonPath('data.meta.per_page', $size)
                ->assertJsonCount($size, 'data.items');
        }
    }

    public function test_unsupported_page_sizes_are_safely_clamped(): void
    {
        $admin = $this->admin();

        for ($i = 1; $i <= 15; $i++) {
            $user = $this->userWithRole(RoleSlug::Teacher, ['email' => "clamp_{$i}@school.test"]);
            $this->staff($user, [
                'staff_number' => 'SRS/TCH/'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
        }

        $this->actingAs($admin)->getJson('/api/v1/staff?per_page=5')
            ->assertOk()
            ->assertJsonPath('data.meta.per_page', 10);

        $this->actingAs($admin)->getJson('/api/v1/staff?per_page=999')
            ->assertOk()
            ->assertJsonPath('data.meta.per_page', 100);

        $this->actingAs($admin)->getJson('/api/v1/staff?per_page=33')
            ->assertOk()
            ->assertJsonPath('data.meta.per_page', 25);
    }

    public function test_admin_can_navigate_to_another_page(): void
    {
        $admin = $this->admin();

        for ($i = 1; $i <= 25; $i++) {
            $user = $this->userWithRole(RoleSlug::Teacher, [
                'name' => 'Alpha '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'email' => "alpha_{$i}@school.test",
            ]);
            $this->staff($user, [
                'staff_number' => 'SRS/TCH/'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
        }

        $page1 = $this->actingAs($admin)->getJson('/api/v1/staff?per_page=10&page=1')
            ->assertOk()
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.from', 1)
            ->assertJsonPath('data.meta.to', 10)
            ->assertJsonCount(10, 'data.items');

        $page2 = $this->actingAs($admin)->getJson('/api/v1/staff?per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonPath('data.meta.from', 11)
            ->assertJsonPath('data.meta.to', 20)
            ->assertJsonCount(10, 'data.items');

        $firstIdPage1 = $page1->json('data.items.0.id');
        $firstIdPage2 = $page2->json('data.items.0.id');
        $this->assertNotEquals($firstIdPage1, $firstIdPage2);
    }

    public function test_search_and_department_filters_work_with_pagination(): void
    {
        $admin = $this->admin();
        $deptMath = $this->department(['name' => 'Mathematics']);
        $deptScience = $this->department(['name' => 'Natural Sciences']);

        $offering = $this->offering();
        $subject = $this->subject(['name' => 'Further Mathematics']);
        $subjectOffering = $this->subjectOffering($offering, $subject);

        $mathTeacher = $this->staff($this->userWithRole(RoleSlug::Teacher, ['name' => 'Newton Isaac']), [
            'department_id' => $deptMath->id,
            'job_title' => 'Senior Math Instructor',
            'staff_number' => 'SRS/TCH/1111',
        ]);
        $this->subjectTeacher($mathTeacher, $subjectOffering);

        $this->staff($this->userWithRole(RoleSlug::Teacher, ['name' => 'Marie Curie']), [
            'department_id' => $deptScience->id,
            'job_title' => 'Head of Physics',
            'staff_number' => 'SRS/TCH/2222',
        ]);

        // Search by user name
        $this->actingAs($admin)->getJson('/api/v1/staff?q=Newton')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Newton Isaac');

        // Search by staff number
        $this->actingAs($admin)->getJson('/api/v1/staff?q=2222')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.staff_number', 'SRS/TCH/2222');

        // Search by job title
        $this->actingAs($admin)->getJson('/api/v1/staff?q=Physics')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Marie Curie');

        // Search by department name
        $this->actingAs($admin)->getJson('/api/v1/staff?q=Natural+Sciences')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Marie Curie');

        // Search by assigned subject name
        $this->actingAs($admin)->getJson('/api/v1/staff?q=Further+Mathematics')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Newton Isaac');

        // Filter by department_id
        $this->actingAs($admin)->getJson('/api/v1/staff?department_id='.$deptMath->id)
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Newton Isaac');
    }

    public function test_form_filtering(): void
    {
        $admin = $this->admin();
        $homeOffering = $this->offering();
        $otherOffering = $this->otherOffering($homeOffering);

        $teacherA = $this->staff($this->userWithRole(RoleSlug::Teacher, ['name' => 'Teacher Alpha']));
        $this->classTeacher($teacherA, $homeOffering);

        $teacherB = $this->staff($this->userWithRole(RoleSlug::Teacher, ['name' => 'Teacher Beta']));
        $this->classTeacher($teacherB, $otherOffering);

        $homeFormName = $homeOffering->classSection->name;

        $response = $this->actingAs($admin)->getJson('/api/v1/staff?form='.urlencode($homeFormName));

        $response->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Teacher Alpha')
            ->assertJsonPath('data.items.0.forms.0', $homeFormName);
    }

    public function test_suspended_staff_filtering_using_user_account_status(): void
    {
        $admin = $this->admin();

        $activeUser = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Active Master', 'status' => UserStatus::Active]);
        $this->staff($activeUser, ['status' => StaffStatus::Active]);

        $suspendedUser = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Suspended Master', 'status' => UserStatus::Suspended]);
        $this->staff($suspendedUser, ['status' => StaffStatus::Inactive]);

        // Filter status=suspended should look up related user's status=suspended
        $suspendedRes = $this->actingAs($admin)->getJson('/api/v1/staff?status=suspended');
        $suspendedRes->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Suspended Master')
            ->assertJsonPath('data.items.0.account_status', 'suspended');

        // Filter status=active should filter StaffProfile.status=active
        $activeRes = $this->actingAs($admin)->getJson('/api/v1/staff?status=active');
        $activeRes->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Active Master')
            ->assertJsonPath('data.items.0.status', 'active');
    }

    public function test_non_admin_users_remain_restricted_to_their_own_staff_profile(): void
    {
        $teacherUserA = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Teacher Self']);
        $staffA = $this->staff($teacherUserA);

        $teacherUserB = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Teacher Other']);
        $this->staff($teacherUserB);

        $response = $this->actingAs($teacherUserA)->getJson('/api/v1/staff');

        $response->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $staffA->id)
            ->assertJsonPath('data.items.0.name', 'Teacher Self')
            ->assertJsonPath('data.summary.total', 1);
    }

    public function test_admin_can_appoint_multiple_forms_to_a_teacher(): void
    {
        $admin = $this->admin();
        $offering1 = $this->offering();
        $offering2 = $this->otherOffering($offering1);

        $level = $offering1->classSection->schoolClass->level;
        $section3 = $this->section(
            $this->schoolClass($level, ['name' => 'JSS 3', 'short_code' => 'J3']),
            ['arm' => 'C', 'name' => 'JSS 3 C'],
        );
        $offering3 = $this->offering($section3, $offering1->academicSession, $offering1->campus);

        // 1. Create staff with multiple classes
        $create = $this->actingAs($admin)->postJson('/api/v1/staff', [
            'name' => 'Mr. Multi Class Master',
            'email' => 'multiclass@school.test',
            'phone' => '08031112233',
            'role' => RoleSlug::Teacher->value,
            'class_section_offering_ids' => [$offering1->id, $offering2->id],
        ]);

        $create->assertCreated()
            ->assertJsonCount(2, 'data.forms')
            ->assertJsonCount(2, 'data.class_section_offering_ids');

        $staffId = $create->json('data.id');

        $this->assertSame(2, ClassTeacherAssignment::query()
            ->where('staff_profile_id', $staffId)
            ->where('is_active', true)
            ->count());

        // 2. Update staff to add a third class
        $update = $this->actingAs($admin)->putJson('/api/v1/staff/'.$staffId, [
            'class_section_offering_ids' => [$offering1->id, $offering2->id, $offering3->id],
        ]);

        $update->assertOk()
            ->assertJsonCount(3, 'data.forms')
            ->assertJsonCount(3, 'data.class_section_offering_ids');

        $this->assertSame(3, ClassTeacherAssignment::query()
            ->where('staff_profile_id', $staffId)
            ->where('is_active', true)
            ->count());

        // 3. Update staff to remove offering2, keeping offering1 and offering3
        $update2 = $this->actingAs($admin)->putJson('/api/v1/staff/'.$staffId, [
            'class_section_offering_ids' => [$offering1->id, $offering3->id],
        ]);

        $update2->assertOk()
            ->assertJsonCount(2, 'data.forms')
            ->assertJsonCount(2, 'data.class_section_offering_ids');

        $this->assertDatabaseHas('class_teacher_assignments', [
            'staff_profile_id' => $staffId,
            'class_section_offering_id' => $offering2->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('class_teacher_assignments', [
            'staff_profile_id' => $staffId,
            'class_section_offering_id' => $offering1->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('class_teacher_assignments', [
            'staff_profile_id' => $staffId,
            'class_section_offering_id' => $offering3->id,
            'is_active' => true,
        ]);
    }
}
