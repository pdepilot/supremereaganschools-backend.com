<?php

namespace Tests\Feature\Rbac;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class RbacApiTest extends TestCase
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

    public function test_school_admin_can_list_roles_and_permissions(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->actingAs($admin)->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($admin)->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_super_admin_bypasses_permission_checks(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);

        $this->assertTrue($super->hasPermission(PermissionSlug::RolesView));
        $this->assertTrue($super->hasPermission(PermissionSlug::FeesManage));

        $this->actingAs($super)->getJson('/api/v1/roles')->assertOk();
    }

    public function test_teacher_cannot_access_roles_api_or_portal_page(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($teacher)->getJson('/api/v1/roles')->assertForbidden();
        $this->actingAs($teacher)->get('/portal/roles')->assertRedirect();
    }

    public function test_accountant_cannot_manage_roles(): void
    {
        $accountant = $this->userWithRole(RoleSlug::Accountant);

        $this->actingAs($accountant)->getJson('/api/v1/roles')->assertForbidden();
        $this->actingAs($accountant)->postJson('/api/v1/roles', [
            'name' => 'Blocked',
        ])->assertForbidden();
    }

    public function test_parent_and_student_cannot_access_rbac(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);
        $student = $this->userWithRole(RoleSlug::Student);

        $this->actingAs($parent)->getJson('/api/v1/roles')->assertForbidden();
        $this->actingAs($student)->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_school_admin_can_create_update_and_assign_roles(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);
        $target = $this->userWithRole(RoleSlug::Teacher, ['email' => 'master@school.test']);

        $create = $this->actingAs($admin)->postJson('/api/v1/roles', [
            'name' => 'Wing Clerk',
            'slug' => 'wing_clerk',
            'permissions' => [
                PermissionSlug::DeskView->value,
                PermissionSlug::StudentsView->value,
            ],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.slug', 'wing_clerk');

        $roleId = $create->json('data.id');

        $this->actingAs($admin)->putJson('/api/v1/roles/'.$roleId, [
            'name' => 'Wing Clerk Desk',
            'permissions' => [PermissionSlug::DeskView->value],
        ])->assertOk()->assertJsonPath('data.name', 'Wing Clerk Desk');

        $this->actingAs($admin)->putJson('/api/v1/users/'.$target->id.'/roles', [
            'roles' => ['wing_clerk'],
        ])->assertOk()
            ->assertJsonPath('data.roles.0', 'wing_clerk');

        $target->refresh()->load('roles.permissions');
        $this->assertTrue($target->hasPermission(PermissionSlug::DeskView));
        $this->assertFalse($target->hasPermission(PermissionSlug::FeesManage));
    }

    public function test_permission_seeders_are_idempotent(): void
    {
        $beforeRoles = Role::query()->count();
        $beforePermissions = Permission::query()->count();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($beforeRoles, Role::query()->count());
        $this->assertSame($beforePermissions, Permission::query()->count());
    }

    public function test_roles_portal_page_loads_for_school_admin(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->actingAs($admin)
            ->get('/portal/roles')
            ->assertOk()
            ->assertSee('data-page="roles"', false)
            ->assertSee('portal-roles.js', false)
            ->assertSee('href="/portal/roles"', false)
            ->assertDontSee('href="roles.html"', false);
    }

    public function test_legacy_roles_html_url_redirects_to_portal_roles(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->actingAs($admin)
            ->get('/portal/roles.html')
            ->assertRedirect('/portal/roles');
    }

    public function test_super_admin_can_open_roles_portal_page(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);

        $this->actingAs($super)
            ->get('/portal/roles')
            ->assertOk()
            ->assertSee('data-page="roles"', false);
    }

    public function test_principal_logs_into_portal_not_staff(): void
    {
        $principal = $this->userWithRole(RoleSlug::Principal, [
            'email' => 'head@school.test',
            'password' => 'password',
        ]);

        $this->postJson('/login', [
            'email' => 'head@school.test',
            'password' => 'password',
            'portal' => 'portal',
        ])->assertOk()->assertJsonPath('data.redirect', '/portal/home');

        $this->postJson('/login', [
            'email' => 'head@school.test',
            'password' => 'password',
            'portal' => 'staff',
        ])->assertUnprocessable();
    }
}
