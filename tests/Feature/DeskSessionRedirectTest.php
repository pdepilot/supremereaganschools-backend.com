<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class DeskSessionRedirectTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_dashboard_stays_open_after_a_staff_intended_url_is_left_in_session(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->get('/staff/grades')->assertRedirect(route('staff.login'));

        $this->actingAs($admin)
            ->get('/portal/dashboard')
            ->assertOk()
            ->assertSee('data-page="dashboard"', false);

        $this->actingAs($admin)
            ->get('/portal/home')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/portal/login')
            ->assertRedirect();

        $this->assertAuthenticatedAs($admin);
        $this->get('/portal/dashboard')->assertOk();
    }

    public function test_portal_login_while_authenticated_does_not_follow_a_staff_intended_url(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->get('/staff/grades')->assertRedirect(route('staff.login'));
        $this->assertNotEmpty(session('url.intended'));
        $this->assertStringContainsString('/staff/grades', (string) session('url.intended'));

        $response = $this->actingAs($admin)->get('/portal/login');

        $response->assertRedirect(route('portal.home'));
        $this->assertStringNotContainsString('/staff', $response->headers->get('Location') ?? '');

        $this->get('/portal/dashboard')->assertOk();
    }

    public function test_dual_role_admin_teacher_keeps_portal_dashboard_on_refresh(): void
    {
        $user = $this->userWithRole(RoleSlug::SchoolAdmin);
        $user->assignRole(RoleSlug::Teacher);

        $this->actingAs($user)
            ->get('/staff/grades')
            ->assertOk();

        $this->actingAs($user)
            ->get('/portal/dashboard')
            ->assertOk()
            ->assertSee('data-page="dashboard"', false);

        $this->actingAs($user)
            ->get('/portal/dashboard')
            ->assertOk()
            ->assertSee('data-page="dashboard"', false);
    }

    public function test_dual_role_user_is_not_pulled_to_staff_from_a_stale_intended_url(): void
    {
        $user = $this->userWithRole(RoleSlug::SchoolAdmin);
        $user->assignRole(RoleSlug::Teacher);

        $this->get('/staff/grades')->assertRedirect(route('staff.login'));

        $this->actingAs($user)
            ->get('/portal/login')
            ->assertRedirect(route('portal.home'));

        $this->get('/portal/dashboard')
            ->assertOk()
            ->assertSee('data-page="dashboard"', false);
    }

    public function test_admin_and_staff_sessions_both_survive_desk_switches(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($admin)
            ->post('/login', [
                'email' => $teacher->email,
                'password' => 'password',
                'portal' => 'staff',
            ])
            ->assertRedirect(route('staff.home'));

        $this->assertAuthenticatedAs($teacher);

        $this->get('/portal/dashboard')
            ->assertOk()
            ->assertSee('data-page="dashboard"', false);
        $this->assertAuthenticatedAs($admin);

        $this->get('/staff')
            ->assertOk();
        $this->assertAuthenticatedAs($teacher);
    }
}
