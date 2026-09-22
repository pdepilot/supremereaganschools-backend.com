<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Services\AuthenticationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class MultiDeskBrowserFlowTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_real_admin_then_staff_json_logins_keep_portal_on_refresh(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, [
            'email' => 'office@school.test',
            'password' => 'password',
        ]);
        $teacher = $this->userWithRole(RoleSlug::Teacher, [
            'email' => 'faculty@school.test',
            'password' => 'password',
        ]);

        $this->postJson('/login', [
            'email' => 'office@school.test',
            'password' => 'password',
            'portal' => 'portal',
        ])
            ->assertOk()
            ->assertJsonPath('data.redirect', '/portal/home');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame($admin->id, session(AuthenticationService::DESK_SESSIONS_KEY.'.portal'));

        $this->get('/portal/home')->assertOk();
        $this->get('/portal/dashboard')->assertOk();

        $this->postJson('/login', [
            'email' => 'faculty@school.test',
            'password' => 'password',
            'portal' => 'staff',
        ])
            ->assertOk()
            ->assertJsonPath('data.redirect', '/staff/home');

        $this->assertAuthenticatedAs($teacher);
        $this->assertSame($admin->id, session(AuthenticationService::DESK_SESSIONS_KEY.'.portal'), 'admin desk session must survive staff login');
        $this->assertSame($teacher->id, session(AuthenticationService::DESK_SESSIONS_KEY.'.staff'));

        $this->get('/portal/home')
            ->assertOk()
            ->assertSee('data-page="dashboard"', false);
        $this->assertAuthenticatedAs($admin);

        $this->get('/portal/dashboard')->assertOk();
        $this->assertAuthenticatedAs($admin);

        $this->get('/staff/home')->assertOk();
        $this->assertAuthenticatedAs($teacher);
    }
}
