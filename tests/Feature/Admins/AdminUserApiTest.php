<?php

namespace Tests\Feature\Admins;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\RbacAuditLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AdminUserApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_super_admin_can_view_admin_users(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);
        $this->userWithRole(RoleSlug::SchoolAdmin, ['email' => 'ops@school.test']);

        $this->actingAs($super)
            ->getJson('/api/v1/admins')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_super_admin_can_create_admin_user_with_role_and_login(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);

        $create = $this->actingAs($super)->postJson('/api/v1/admins', [
            'first_name' => 'Ada',
            'last_name' => 'Okoro',
            'email' => 'ada.okoro@school.test',
            'role' => RoleSlug::ContentManager->value,
            'password' => 'securePass1',
            'password_confirmation' => 'securePass1',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.email', 'ada.okoro@school.test')
            ->assertJsonPath('data.role', RoleSlug::ContentManager->value)
            ->assertJsonMissingPath('data.password');

        $admin = User::query()->where('email', 'ada.okoro@school.test')->firstOrFail();
        $this->assertTrue($admin->hasRole(RoleSlug::ContentManager));
        $this->assertTrue($admin->hasPermission(PermissionSlug::NewsManage));
        $this->assertFalse($admin->hasPermission(PermissionSlug::FeesManage));
        $this->assertTrue(Hash::check('securePass1', $admin->password));
        $this->assertDatabaseHas('rbac_audit_logs', [
            'action' => 'admin.created',
            'subject_id' => $admin->id,
        ]);

        $this->postJson('/login', [
            'email' => 'ada.okoro@school.test',
            'password' => 'securePass1',
            'portal' => 'portal',
        ])->assertOk()->assertJsonPath('data.redirect', '/portal/home');
    }

    public function test_super_admin_can_edit_suspend_reactivate_and_delete_admin(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);
        $target = $this->userWithRole(RoleSlug::Accountant, [
            'email' => 'books@school.test',
            'password' => 'password',
        ]);

        $this->actingAs($super)->putJson('/api/v1/admins/'.$target->id, [
            'first_name' => 'Books',
            'last_name' => 'Officer',
            'email' => 'books.desk@school.test',
            'role' => RoleSlug::Principal->value,
        ])->assertOk()
            ->assertJsonPath('data.email', 'books.desk@school.test')
            ->assertJsonPath('data.role', RoleSlug::Principal->value);

        $this->actingAs($super)
            ->postJson('/api/v1/admins/'.$target->id.'/suspend')
            ->assertOk()
            ->assertJsonPath('data.status', UserStatus::Suspended->value);

        $this->postJson('/login', [
            'email' => 'books.desk@school.test',
            'password' => 'password',
            'portal' => 'portal',
        ])->assertUnprocessable();

        $this->actingAs($super)
            ->postJson('/api/v1/admins/'.$target->id.'/reinstate')
            ->assertOk()
            ->assertJsonPath('data.status', UserStatus::Active->value);

        $this->postJson('/login', [
            'email' => 'books.desk@school.test',
            'password' => 'password',
            'portal' => 'portal',
        ])->assertOk();

        $this->actingAs($super)
            ->deleteJson('/api/v1/admins/'.$target->id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_super_admin_cannot_delete_or_suspend_self(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);

        $this->actingAs($super)
            ->postJson('/api/v1/admins/'.$super->id.'/suspend')
            ->assertUnprocessable();

        $this->actingAs($super)
            ->deleteJson('/api/v1/admins/'.$super->id)
            ->assertUnprocessable();
    }

    public function test_system_prevents_removing_final_active_super_admin(): void
    {
        $only = $this->userWithRole(RoleSlug::SuperAdmin, ['email' => 'only@school.test']);
        $schoolAdmin = $this->userWithRole(RoleSlug::SchoolAdmin, ['email' => 'helper@school.test']);

        $this->actingAs($only)
            ->postJson('/api/v1/admins/'.$only->id.'/suspend')
            ->assertUnprocessable();

        $this->actingAs($only)
            ->putJson('/api/v1/admins/'.$only->id, [
                'role' => RoleSlug::SchoolAdmin->value,
            ])
            ->assertUnprocessable();

        $this->assertTrue($schoolAdmin->fresh()->hasRole(RoleSlug::SchoolAdmin));
    }

    public function test_second_super_admin_can_be_suspended_when_another_remains(): void
    {
        $primary = $this->userWithRole(RoleSlug::SuperAdmin, ['email' => 'primary@school.test']);
        $secondary = $this->userWithRole(RoleSlug::SuperAdmin, ['email' => 'secondary@school.test']);

        $this->actingAs($primary)
            ->postJson('/api/v1/admins/'.$secondary->id.'/suspend')
            ->assertOk()
            ->assertJsonPath('data.status', UserStatus::Suspended->value);
    }

    public function test_school_admin_parent_student_and_teacher_cannot_manage_admins(): void
    {
        $schoolAdmin = $this->userWithRole(RoleSlug::SchoolAdmin);
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $parent = $this->userWithRole(RoleSlug::Parent);
        $student = $this->userWithRole(RoleSlug::Student);

        foreach ([$schoolAdmin, $teacher, $parent, $student] as $actor) {
            $this->actingAs($actor)->getJson('/api/v1/admins')->assertForbidden();
            $this->actingAs($actor)->postJson('/api/v1/admins', [
                'first_name' => 'Blocked',
                'last_name' => 'User',
                'email' => 'blocked.'.uniqid().'@school.test',
                'role' => RoleSlug::Accountant->value,
                'password' => 'securePass1',
                'password_confirmation' => 'securePass1',
            ])->assertForbidden();
        }
    }

    public function test_admins_portal_page_loads_for_super_admin_only(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);
        $schoolAdmin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->actingAs($super)
            ->get('/portal/admins')
            ->assertOk()
            ->assertSee('data-page="admins"', false)
            ->assertSee('portal-admins.js', false)
            ->assertSee('href="/portal/admins"', false);

        $this->actingAs($schoolAdmin)
            ->get('/portal/admins')
            ->assertForbidden();
    }

    public function test_super_admin_can_update_profile_and_password(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin, [
            'email' => 'chief@school.test',
            'password' => 'oldSecret1',
        ]);

        $this->actingAs($super)->putJson('/api/v1/me', [
            'name' => 'Chief Officer',
            'email' => 'chief.desk@school.test',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Chief Officer')
            ->assertJsonPath('data.email', 'chief.desk@school.test');

        $this->actingAs($super)->putJson('/api/v1/me/password', [
            'current_password' => 'wrong-password',
            'password' => 'newSecret12',
            'password_confirmation' => 'newSecret12',
        ])->assertUnprocessable();

        $this->actingAs($super)->putJson('/api/v1/me/password', [
            'current_password' => 'oldSecret1',
            'password' => 'newSecret12',
            'password_confirmation' => 'newSecret12',
        ])->assertOk();

        $this->assertTrue(Hash::check('newSecret12', $super->fresh()->password));
        $this->assertDatabaseHas('rbac_audit_logs', [
            'action' => 'account.password_changed',
            'actor_id' => $super->id,
        ]);
        $this->assertFalse(
            RbacAuditLog::query()
                ->where('action', 'account.password_changed')
                ->get()
                ->contains(fn (RbacAuditLog $log) => str_contains(json_encode($log->meta ?? []), 'newSecret12'))
        );
    }

    public function test_super_admin_can_reset_another_admin_password(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);
        $target = $this->userWithRole(RoleSlug::AdmissionsOfficer, [
            'email' => 'admit@school.test',
            'password' => 'password',
        ]);

        $this->actingAs($super)->putJson('/api/v1/admins/'.$target->id.'/password', [
            'password' => 'freshPass99',
            'password_confirmation' => 'freshPass99',
        ])->assertOk();

        $this->postJson('/login', [
            'email' => 'admit@school.test',
            'password' => 'freshPass99',
            'portal' => 'portal',
        ])->assertOk();
    }

    public function test_parent_and_student_roles_are_not_appointable(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);

        $this->actingAs($super)->postJson('/api/v1/admins', [
            'first_name' => 'No',
            'last_name' => 'Parent',
            'email' => 'noparent@school.test',
            'role' => RoleSlug::Parent->value,
            'password' => 'securePass1',
            'password_confirmation' => 'securePass1',
        ])->assertUnprocessable();

        $this->actingAs($super)->getJson('/api/v1/admins/roles')
            ->assertOk()
            ->assertJsonMissing(['slug' => RoleSlug::Parent->value])
            ->assertJsonMissing(['slug' => RoleSlug::Student->value]);
    }

    public function test_account_portal_page_loads(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);

        $this->actingAs($super)
            ->get('/portal/account')
            ->assertOk()
            ->assertSee('data-page="account"', false)
            ->assertSee('portal-account.js', false);
    }

    public function test_school_admin_does_not_receive_admins_permissions_by_default(): void
    {
        $schoolAdmin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->assertFalse($schoolAdmin->hasPermission(PermissionSlug::AdminsView));
        $this->assertFalse($schoolAdmin->hasPermission(PermissionSlug::AdminsCreate));
        $this->assertTrue($schoolAdmin->hasPermission(PermissionSlug::DeskView));
    }
}
