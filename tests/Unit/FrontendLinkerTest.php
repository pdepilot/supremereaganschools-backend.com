<?php

namespace Tests\Unit;

use App\Support\FrontendLinker;
use Tests\TestCase;

class FrontendLinkerTest extends TestCase
{
    public function test_rewrites_relative_and_bare_asset_paths(): void
    {
        $html = (new FrontendLinker)->rewrite(<<<'HTML'
<img src="./Image/logo_main.png">
<img src="../Image/logo_main.png">
<img src="Image/home.jpg?v=1">
<link href="./CSS/index.css">
<script src="../JS/nav.js"></script>
<div style="background-image: url('./Image/school_view.jpg')"></div>
HTML, 'public');

        $this->assertStringContainsString('src="/site/Image/logo_main.png"', $html);
        $this->assertStringContainsString('src="/site/Image/home.jpg?v=1"', $html);
        $this->assertStringContainsString('href="/site/CSS/index.css"', $html);
        $this->assertStringContainsString('src="/site/JS/nav.js"', $html);
        $this->assertStringContainsString("url('/site/Image/school_view.jpg')", $html);
        $this->assertStringNotContainsString('src="Image/', $html);
        $this->assertStringNotContainsString('src="./Image/', $html);
        $this->assertStringNotContainsString('/site/site/', $html);
    }

    public function test_does_not_double_rewrite_site_asset_paths(): void
    {
        $html = (new FrontendLinker)->rewrite(
            '<img src="/site/Image/logo_main.png"><link href="/site/CSS/index.css">',
            'public',
        );

        $this->assertSame(
            '<img src="/site/Image/logo_main.png"><link href="/site/CSS/index.css">',
            $html,
        );
    }

    public function test_rewrites_admin_roles_html_to_portal_route(): void
    {
        $html = (new FrontendLinker)->rewrite(
            '<a class="rail-btn" href="roles.html"><span>Roles</span></a>',
            'admin',
        );

        $this->assertStringContainsString('href="/portal/roles"', $html);
        $this->assertStringNotContainsString('href="roles.html"', $html);
    }

    public function test_rewrites_admins_and_account_html_to_portal_routes(): void
    {
        $html = (new FrontendLinker)->rewrite(
            '<a href="admins.html">Admins</a><a href="account.html">Profile</a>',
            'admin',
        );

        $this->assertStringContainsString('href="/portal/admins"', $html);
        $this->assertStringContainsString('href="/portal/account"', $html);
    }

    public function test_injects_idle_session_script_on_admin_desk_pages(): void
    {
        $html = (new FrontendLinker)->rewrite(<<<'HTML'
<body data-page="dashboard">
  <a data-logout href="#">Log out</a>
  <script src="../JS/admin-command.js"></script>
</body>
HTML, 'admin');

        $this->assertStringContainsString('src="/site/JS/portal-session.js"', $html);
        $this->assertStringContainsString('src="/site/JS/portal-desk-bell.js"', $html);
        $this->assertStringContainsString('src="/site/JS/admin-command.js"', $html);
        $this->assertLessThan(
            strpos($html, 'admin-command.js'),
            strpos($html, 'portal-session.js'),
        );
        $this->assertLessThan(
            strpos($html, 'admin-command.js'),
            strpos($html, 'portal-desk-bell.js'),
        );
    }

    public function test_injects_notification_bell_on_parent_desk_pages(): void
    {
        $html = (new FrontendLinker)->rewrite(<<<'HTML'
<body class="faculty-house pupil-house" data-page="parent_messages">
  <a data-logout href="#">Log out</a>
  <script src="../JS/portal-parent-pages.js"></script>
</body>
HTML, 'parent');

        $this->assertStringContainsString('src="/site/JS/portal-desk-bell.js"', $html);
        $this->assertStringContainsString('src="/site/JS/portal-session.js"', $html);
    }

    public function test_injects_notification_bell_on_staff_and_student_home_desks(): void
    {
        $staff = (new FrontendLinker)->rewrite(<<<'HTML'
<body class="faculty-house" data-page="staff-desk">
  <header class="hero"><div class="hero-top"><div class="live-pill"></div></div></header>
  <a data-logout href="#">Log out</a>
  <script src="../JS/portal-staff-desk.js"></script>
</body>
HTML, 'staff');

        $student = (new FrontendLinker)->rewrite(<<<'HTML'
<body class="faculty-house pupil-house" data-page="student-desk">
  <header class="hero"><div class="hero-top"><div class="live-pill"></div></div></header>
  <a data-logout href="#">Log out</a>
  <script src="../JS/portal-student-desk.js"></script>
</body>
HTML, 'student');

        $this->assertStringContainsString('src="/site/JS/portal-desk-bell.js"', $staff);
        $this->assertStringContainsString('src="/site/JS/portal-desk-bell.js"', $student);
    }

    public function test_session_lifetime_defaults_to_fifteen_minutes(): void
    {
        $this->assertSame(15, (int) config('session.lifetime'));
    }
}
