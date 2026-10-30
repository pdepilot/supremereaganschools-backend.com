<?php

namespace Tests\Feature\Portal;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalDesignReplicationTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_all_admin_portal_pages_render_with_bricks_site_design(): void
    {
        $admin = $this->admin();

        $pages = [
            '/portal/dashboard',
            '/portal/students',
            '/portal/teachers',
            '/portal/classes',
            '/portal/academic-sessions',
            '/portal/timetable',
            '/portal/fees',
            '/portal/grades',
            '/portal/announcements',
            '/portal/news',
            '/portal/events',
            '/portal/email',
            '/portal/contact',
            '/portal/messages',
            '/portal/reports',
            '/portal/settings',
            '/portal/roles',
            '/portal/account',
            '/portal/nursery',
            '/portal/primary',
            '/portal/secondary',
        ];

        foreach ($pages as $url) {
            $response = $this->actingAs($admin)->get($url);
            $response->assertOk();
            $response->assertSee('admin-command.css', false);
            $response->assertSee('admin-command.js', false);
            $response->assertSee('Cormorant+Garamond', false);
            $response->assertSee('Manrope', false);
        }
    }

    public function test_super_admin_can_access_admins_page(): void
    {
        $superAdmin = $this->userWithRole(\App\Enums\RoleSlug::SuperAdmin);

        $response = $this->actingAs($superAdmin)->get('/portal/admins');
        $response->assertOk();
        $response->assertSee('admin-command.css', false);
        $response->assertSee('admin-command.js', false);
        $response->assertSee('Cormorant+Garamond', false);
        $response->assertSee('Manrope', false);
    }

    public function test_stylesheet_contains_bricks_site_design_tokens(): void
    {
        $css = file_get_contents(public_path('site/CSS/admin-command.css'));

        // Palette & tokens
        $this->assertStringContainsString('--bg: #F5F8F5;', $css);
        $this->assertStringContainsString('--accent: #275C3E;', $css);
        $this->assertStringContainsString('--heading: #123321;', $css);
        $this->assertStringContainsString('--gold: #C29B38;', $css);
        $this->assertStringContainsString("--display: 'Cormorant Garamond'", $css);
        $this->assertStringContainsString("--body: 'Manrope'", $css);
        $this->assertStringContainsString('[data-theme="dark"]', $css);
        $this->assertStringContainsString('--sidebar: 264px;', $css);

        // Required academic sessions selector for regressions
        $this->assertStringContainsString(
            '[data-page="academic_sessions"] .split:not(:has([data-session-books]:not([hidden])))',
            $css
        );
    }
}
