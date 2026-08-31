<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class FrontendRoutingTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_public_pages_use_laravel_routes_without_html_extensions(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee('About Us', false)
            ->assertSee('Who We Are', false)
            ->assertSee('href="/about"', false)
            ->assertSee('href="/news"', false)
            ->assertSee('<strong>Resources</strong>', false)
            ->assertDontSee('class="house-link" href="/resources"', false)
            ->assertSee('href="/privacy"', false)
            ->assertSee('href="/terms"', false)
            ->assertSee('href="/site/CSS/index.css', false)
            ->assertSee('href="/site/CSS/about.css"', false)
            ->assertSee('classic-menu-wing', false)
            ->assertSee('classic-menu-house-trigger', false)
            ->assertSee('classic-menu-panel', false)
            ->assertDontSee('href="./about.html"', false);

        $this->get('/admissions')->assertOk()->assertSee('Admissions', false);
        $this->get('/contact')->assertOk();

        $this->get('/nursery')
            ->assertOk()
            ->assertSee('Early Years', false)
            ->assertSee('Nursery School', false)
            ->assertSee('href="/site/CSS/wings.css"', false)
            ->assertDontSee('href="./nursery.html"', false);

        $this->get('/primary')
            ->assertOk()
            ->assertSee('Primary School', false)
            ->assertSee('What We Build', false)
            ->assertSee('href="/site/CSS/wings.css"', false);

        $this->get('/secondary')
            ->assertOk()
            ->assertSee('Secondary School', false)
            ->assertSee('Coding and Robotics', false)
            ->assertSee('href="/site/CSS/wings.css"', false);

        $this->get('/branches')
            ->assertOk()
            ->assertSee('Our Schools', false)
            ->assertSee('15 Spibat Road', false)
            ->assertSee('href="/site/CSS/branches.css"', false)
            ->assertSee('href="/student/login"', false)
            ->assertDontSee('href="./branches.html"', false);

        $this->get('/alumni')
            ->assertOk()
            ->assertSee('Alumni', false)
            ->assertSee('href="/alumni"', false)
            ->assertDontSee('href="./pta.html"', false)
            ->assertDontSee('href="/pta"', false);
    }

    public function test_home_hero_images_are_rewritten_to_site_assets(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('src="/site/Image/home.jpg', false)
            ->assertSee('href="/staff/login"', false)
            ->assertDontSee('href="/portal/login"', false)
            ->assertSee('href="/alumni"', false)
            ->assertDontSee('href="/pta"', false)
            ->assertSee('The House', false)
            ->assertSee('How a child joins the house', false)
            ->assertSee('GROW governs the life of the school', false)
            ->assertSee('class="site-chrome"', false)
            ->assertSee('src="/site/Image/images.jpg', false)
            ->assertSee('src="/site/Image/secondary (1).jpg"', false)
            ->assertSee('src="/site/Image/primary.jpg"', false)
            ->assertSee('src="/site/Image/sports_pics.jpg"', false)
            ->assertSee('src="/site/Image/sports_pics2.jpg"', false)
            ->assertSee('src="/site/Image/founders.jpg"', false)
            ->assertSee('id="heroSlideshow"', false)
            ->assertSee('data-bs-pause="false"', false)
            ->assertSee('/site/JS/hero-slideshow.js', false)
            ->assertSee('/site/CSS/index.css', false)
            ->assertDontSee('src="Image/', false)
            ->assertDontSee('src="./Image/', false);
    }

    public function test_cookie_banner_is_a_fixed_dock_not_a_footer_strip(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-consent-banner', false)
            ->assertSee('Cookie choices', false)
            ->assertSee('/site/JS/site-consent.js', false)
            ->assertDontSee('position:sticky;bottom:0', false);

        $css = (string) file_get_contents(public_path('site/CSS/index.css'));
        $this->assertStringContainsString('.consent-banner {', $css);
        $this->assertStringContainsString('position: fixed;', $css);
        $this->assertStringContainsString('body.is-consenting', $css);
    }

    public function test_legacy_html_paths_redirect_to_laravel_routes(): void
    {
        $this->get('/site/about.html')->assertRedirect('/about');
        $this->get('/site/index.html')->assertRedirect('/');
        $this->get('/site/alumni.html')->assertRedirect('/alumni');
        $this->get('/site/staffLogin.html')->assertRedirect('/staff/login');
        $this->get('/site/portal/dashboard.html')->assertRedirect('/portal/dashboard');
        $this->get('/site/staff/attendance.html')->assertRedirect('/staff/attendance');
        $this->get('/site/parent_student/fees.html')->assertRedirect('/parent/fees');
        $this->get('/site/admin/dashboard.html')->assertRedirect('/portal/dashboard');
    }

    public function test_staff_and_parent_pages_require_login(): void
    {
        $this->get('/staff/attendance')->assertRedirect('/staff/login');
        $this->get('/parent/assignments')->assertRedirect('/parent/login');
        $this->get('/portal/dashboard')->assertRedirect('/portal/login');
    }

    public function test_authenticated_portals_open_clean_routes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)
            ->get('/portal/announcements')
            ->assertOk()
            ->assertSee('portal-classroom.js', false)
            ->assertSee('href="/portal/dashboard"', false)
            ->assertSee('href="/portal/email"', false)
            ->assertSee('href="/portal/contact"', false);

        $this->actingAs($admin)
            ->get('/portal/email')
            ->assertOk()
            ->assertSee('portal-email.js', false)
            ->assertSee('Email centre', false);

        $this->actingAs($admin)
            ->get('/portal/contact')
            ->assertOk()
            ->assertSee('portal-contact.js', false)
            ->assertSee('Contact desk', false);

        $this->actingAs($admin)
            ->get('/portal/reports')
            ->assertOk()
            ->assertSee('portal-reports.js', false)
            ->assertSee('School reports', false);

        $this->actingAs($admin)
            ->get('/portal/grades')
            ->assertOk()
            ->assertSee('portal-grades.js', false)
            ->assertSee('Marks &amp; results', false);

        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->actingAs($teacher)
            ->get('/staff')
            ->assertOk()
            ->assertSee('href="/staff/attendance"', false)
            ->assertSee('portal-staff-desk.js', false)
            ->assertSee('data-page="staff-desk"', false);

        $parent = $this->userWithRole(RoleSlug::Parent);
        $this->actingAs($parent)
            ->get('/parent')
            ->assertOk()
            ->assertSee('href="/parent/assignments"', false)
            ->assertSee('data-page="parent-desk"', false);
    }
}
