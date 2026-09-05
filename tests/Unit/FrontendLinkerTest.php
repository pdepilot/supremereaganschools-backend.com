<?php

namespace Tests\Unit;

use App\Support\FrontendLinker;
use PHPUnit\Framework\TestCase;

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
}
