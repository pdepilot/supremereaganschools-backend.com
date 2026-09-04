<?php

namespace Tests\Feature\Admins;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AdminAccountApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    public function test_super_admin_can_create_admin_with_permissions(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin, [
            'name' => 'House Super',
            'email' => 'super@school.test',
        ]);

        $create = $this->actingAs($super)->postJson('/api/v1/admins', [
            'name' => 'Office Admin',
            'email' => 'office@school.test',
            'password' => 'password123',
            'permissions' => [
                PermissionSlug::Pupils->value,
                PermissionSlug::Fees->value,
            ],
        ]);

        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'office@school.test')
            ->assertJsonPath('data.roles.0', RoleSlug::SchoolAdmin->value)
            ->assertJsonPath('data.permissions', [
                PermissionSlug::Pupils->value,
                PermissionSlug::Fees->value,
            ]);

        $admin = User::query()->where('email', 'office@school.test')->firstOrFail();
        $this->assertTrue(Hash::check('password123', $admin->getAuthPassword()));
        $this->assertTrue($admin->hasPermission(PermissionSlug::Pupils));
        $this->assertFalse($admin->hasPermission(PermissionSlug::News));
    }

    public function test_school_admin_cannot_create_admins_but_can_change_login_details(): void
    {
        $schoolAdmin = $this->userWithRole(RoleSlug::SchoolAdmin, [
            'email' => 'desk@school.test',
        ]);
        $target = $this->userWithRole(RoleSlug::SchoolAdmin, [
            'name' => 'Old Name',
            'email' => 'target@school.test',
            'password' => 'password',
        ]);

        $this->actingAs($schoolAdmin)->postJson('/api/v1/admins', [
            'name' => 'Blocked',
            'email' => 'blocked@school.test',
            'password' => 'password123',
        ])->assertForbidden();

        $this->actingAs($schoolAdmin)->putJson('/api/v1/admins/'.$target->id, [
            'name' => 'New Name',
            'email' => 'new-target@school.test',
            'password' => 'freshpass1',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new-target@school.test');

        $target->refresh();
        $this->assertTrue(Hash::check('freshpass1', $target->getAuthPassword()));
    }

    public function test_school_admin_cannot_assign_permissions_or_edit_super_admin_login(): void
    {
        $schoolAdmin = $this->userWithRole(RoleSlug::SchoolAdmin);
        $target = $this->userWithRole(RoleSlug::SchoolAdmin, ['email' => 'clerk@school.test']);
        $super = $this->userWithRole(RoleSlug::SuperAdmin, ['email' => 'root@school.test']);

        $this->actingAs($schoolAdmin)->putJson('/api/v1/admins/'.$target->id, [
            'permissions' => [PermissionSlug::News->value],
        ])->assertForbidden();

        $this->actingAs($schoolAdmin)->putJson('/api/v1/admins/'.$super->id, [
            'email' => 'hacked@school.test',
        ])->assertForbidden();
    }

    public function test_super_admin_can_suspend_and_remove_school_admin(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);
        $target = $this->userWithRole(RoleSlug::SchoolAdmin, ['email' => 'gone@school.test']);

        $this->actingAs($super)->postJson('/api/v1/admins/'.$target->id.'/suspend')
            ->assertOk()
            ->assertJsonPath('data.status', UserStatus::Suspended->value);

        $this->actingAs($super)->deleteJson('/api/v1/admins/'.$target->id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['email' => 'gone@school.test']);
    }

    public function test_admins_portal_page_loads_for_school_admin(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->actingAs($admin)
            ->get('/portal/admins')
            ->assertOk()
            ->assertSee('data-page="admins"', false)
            ->assertSee('data-admins-table', false)
            ->assertSee('data-admins-form', false)
            ->assertSee('portal-admins.js', false);
    }

    public function test_me_includes_permissions_for_super_admin(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);

        $this->actingAs($super)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.is_super_admin', true)
            ->assertJsonFragment(['permissions' => collect(PermissionSlug::cases())->map->value->all()]);
    }
}
