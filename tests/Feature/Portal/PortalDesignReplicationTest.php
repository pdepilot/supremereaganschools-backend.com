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

    public function test_all_admin_portal_pages_render_with_original_portal_design(): void
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
            $response->assertSee('family=Montserrat', false);
            $response->assertDontSee('Cormorant+Garamond', false);
            $response->assertDontSee('Manrope', false);
        }
    }

    public function test_super_admin_can_access_admins_page(): void
    {
        $superAdmin = $this->userWithRole(\App\Enums\RoleSlug::SuperAdmin);

        $response = $this->actingAs($superAdmin)->get('/portal/admins');
        $response->assertOk();
        $response->assertSee('admin-command.css', false);
        $response->assertSee('admin-command.js', false);
        $response->assertSee('family=Montserrat', false);
        $response->assertDontSee('Cormorant+Garamond', false);
        $response->assertDontSee('Manrope', false);
    }

    public function test_stylesheet_contains_original_portal_design_tokens(): void
    {
        $css = file_get_contents(public_path('site/CSS/admin-command.css'));

        // Palette & tokens for original Supreme Reagan Schools design
        $this->assertStringContainsString('--navy: #243A8F;', $css);
        $this->assertStringContainsString('--navy-deep: #243A8F;', $css);
        $this->assertStringContainsString('--accent: #5B2D8B;', $css);
        $this->assertStringContainsString('--chalk: #f4efe4;', $css);
        $this->assertStringContainsString('--paper: #fffdf8;', $css);
        $this->assertStringContainsString('--ink: #243A8F;', $css);
        $this->assertStringContainsString('"Montserrat"', $css);
        $this->assertStringContainsString('grid-template-columns: 92px minmax(0, 1fr);', $css);

        // Staff pagination feature
        $this->assertStringContainsString('.staff-pagination', $css);

        // Required academic sessions selector for regressions
        $this->assertStringContainsString(
            '[data-page="academic_sessions"] .split:not(:has([data-session-books]:not([hidden])))',
            $css
        );
    }
}
