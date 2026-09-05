<?php

namespace Tests\Feature\Portal;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalDeskAccessTest extends TestCase
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

    public function test_super_admin_can_open_all_portal_modules(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);

        foreach ([
            'dashboard',
            'students',
            'teachers',
            'classes',
            'academic-sessions',
            'fees',
            'grades',
            'news',
            'roles',
            'admins',
            'settings',
            'account',
        ] as $page) {
            $this->actingAs($super)->get('/portal/'.$page)->assertOk();
        }
    }

    public function test_content_manager_sees_only_content_modules(): void
    {
        $manager = $this->userWithRole(RoleSlug::ContentManager);

        $this->actingAs($manager)->get('/portal/dashboard')->assertOk();
        $this->actingAs($manager)->get('/portal/news')->assertOk();
        $this->actingAs($manager)->get('/portal/announcements')->assertOk();
        $this->actingAs($manager)->get('/portal/email')->assertOk();
        $this->actingAs($manager)->get('/portal/contact')->assertOk();
        $this->actingAs($manager)->get('/portal/account')->assertOk();

        $this->actingAs($manager)->get('/portal/students')->assertForbidden();
        $this->actingAs($manager)->get('/portal/fees')->assertForbidden();
        $this->actingAs($manager)->get('/portal/roles')->assertForbidden();
        $this->actingAs($manager)->get('/portal/admins')->assertForbidden();
        $this->actingAs($manager)->get('/portal/settings')->assertForbidden();
    }

    public function test_accountant_sees_finance_not_academic_or_security(): void
    {
        $accountant = $this->userWithRole(RoleSlug::Accountant);

        $this->actingAs($accountant)->get('/portal/dashboard')->assertOk();
        $this->actingAs($accountant)->get('/portal/fees')->assertOk();
        $this->actingAs($accountant)->get('/portal/reports')->assertOk();
        $this->actingAs($accountant)->get('/portal/students')->assertOk();

        $this->actingAs($accountant)->get('/portal/grades')->assertForbidden();
        $this->actingAs($accountant)->get('/portal/news')->assertForbidden();
        $this->actingAs($accountant)->get('/portal/roles')->assertForbidden();
        $this->actingAs($accountant)->get('/portal/admins')->assertForbidden();
        $this->actingAs($accountant)->get('/portal/settings')->assertForbidden();
    }

    public function test_examination_officer_sees_marks_not_finance_or_admins(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);

        $this->actingAs($officer)->get('/portal/grades')->assertOk();
        $this->actingAs($officer)->get('/portal/students')->assertOk();
        $this->actingAs($officer)->get('/portal/fees')->assertForbidden();
        $this->actingAs($officer)->get('/portal/admins')->assertForbidden();
        $this->actingAs($officer)->get('/portal/roles')->assertForbidden();
    }

    public function test_school_admin_cannot_open_admins_without_admins_permission(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->assertFalse($admin->hasPermission(PermissionSlug::AdminsView));
        $this->actingAs($admin)->get('/portal/admins')->assertForbidden();
        $this->actingAs($admin)->get('/portal/roles')->assertOk();
        $this->actingAs($admin)->get('/portal/students')->assertOk();
    }

    public function test_dashboard_api_hides_unpermitted_sections_for_content_manager(): void
    {
        $manager = $this->userWithRole(RoleSlug::ContentManager, ['name' => 'Content Lead']);

        $this->actingAs($manager)
            ->getJson('/api/v1/portal-dashboard')
            ->assertOk()
            ->assertJsonPath('data.visibility.pupils', false)
            ->assertJsonPath('data.visibility.fees', false)
            ->assertJsonPath('data.visibility.news', true)
            ->assertJsonPath('data.visibility.inbox', true)
            ->assertJsonPath('data.metrics.pupils', null)
            ->assertJsonPath('data.metrics.fees_count', null)
            ->assertJsonPath('data.tickets', [])
            ->assertJsonPath('data.wings', []);
    }

    public function test_dashboard_api_shows_finance_for_accountant(): void
    {
        $accountant = $this->userWithRole(RoleSlug::Accountant);

        $this->actingAs($accountant)
            ->getJson('/api/v1/portal-dashboard')
            ->assertOk()
            ->assertJsonPath('data.visibility.fees', true)
            ->assertJsonPath('data.visibility.pupils', true)
            ->assertJsonPath('data.visibility.news', false)
            ->assertJsonPath('data.metrics.fees_count', 0);
    }

    public function test_role_change_updates_page_access(): void
    {
        $super = $this->userWithRole(RoleSlug::SuperAdmin);
        $user = $this->userWithRole(RoleSlug::ContentManager, ['email' => 'switch@school.test']);

        $this->actingAs($user)->get('/portal/fees')->assertForbidden();

        $this->actingAs($super)->putJson('/api/v1/admins/'.$user->id, [
            'role' => RoleSlug::Accountant->value,
        ])->assertOk();

        $user->refresh()->unsetRelation('roles');
        $this->actingAs($user)->get('/portal/fees')->assertOk();
        $this->actingAs($user)->get('/portal/news')->assertForbidden();
    }

    public function test_student_and_parent_cannot_open_portal_modules(): void
    {
        $student = $this->userWithRole(RoleSlug::Student);
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($student)->get('/portal/dashboard')->assertRedirect();
        $this->actingAs($parent)->get('/portal/dashboard')->assertRedirect();
    }

    public function test_view_permission_does_not_imply_create_on_students_api(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);

        $this->assertTrue($officer->hasPermission(PermissionSlug::StudentsView));
        $this->assertFalse($officer->hasPermission(PermissionSlug::StudentsCreate));

        $this->actingAs($officer)
            ->postJson('/api/v1/students', [
                'surname' => 'Test',
                'first_name' => 'Pupil',
                'admission_number' => 'SRS/TEST/1',
            ])
            ->assertForbidden();
    }

    public function test_can_access_desk_page_helper_respects_permissions(): void
    {
        $manager = $this->userWithRole(RoleSlug::ContentManager);
        $super = $this->userWithRole(RoleSlug::SuperAdmin);

        $this->assertTrue($manager->canAccessDeskPage('news'));
        $this->assertFalse($manager->canAccessDeskPage('fees'));
        $this->assertFalse($manager->canAccessDeskPage('admins'));
        $this->assertTrue($manager->canAccessDeskPage('account'));
        $this->assertTrue($super->canAccessDeskPage('admins'));
        $this->assertTrue($super->canAccessDeskPage('fees'));
    }
}
