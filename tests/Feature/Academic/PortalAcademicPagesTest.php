<?php

namespace Tests\Feature\Academic;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalAcademicPagesTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_existing_admin_structure_pages_load_with_backend_hooks(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/settings')
            ->assertOk()
            ->assertSee('data-school-form', false)
            ->assertSee('Add a session', false)
            ->assertSee('data-account-form', false)
            ->assertSee('data-password-form', false)
            ->assertSee('data-desk-list', false)
            ->assertSee('portal-structure.js', false);

        $this->actingAs($admin)
            ->get('/portal/academic-sessions')
            ->assertOk()
            ->assertSee('data-page="sessions"', false)
            ->assertSee('data-session-form', false)
            ->assertSee('id="add-session"', false)
            ->assertSee('Add a session', false)
            ->assertSee('id="yearLive"', false)
            ->assertSee('data-session-list', false)
            ->assertSee('data-desk-alert', false)
            ->assertSee('portal-structure.js', false)
            ->assertDontSee('Last session', false)
            ->assertDontSee('Closed July 2025', false)
            ->assertDontSee('>25/26<', false);

        $this->actingAs($admin)
            ->get('/portal/classes')
            ->assertOk()
            ->assertSee('data-page="classes"', false)
            ->assertSee('data-form-table', false)
            ->assertSee('data-form-form', false)
            ->assertSee('data-subject-form', false)
            ->assertSee('data-subject-catalogue', false)
            ->assertSee('data-form-pager', false)
            ->assertSee('data-form-pages', false)
            ->assertSee('data-desk-alert', false)
            ->assertSee('portal-structure.js', false)
            ->assertDontSee('Mrs. Eze', false)
            ->assertDontSee('Primary 4B', false)
            ->assertDontSee('2025/2026 · Owerri campus', false);

        $structureJs = (string) file_get_contents(public_path('site/JS/portal-structure.js'));
        $this->assertStringContainsString('PAGE_SIZE = 10', $structureJs);
        $this->assertStringContainsString('data-appoint-form', $structureJs);
        $this->assertStringContainsString('data-subjects-form', $structureJs);
        $this->assertStringContainsString('/api/v1/subjects', $structureJs);
        $this->assertStringContainsString('/api/v1/subject-offerings', $structureJs);
        $this->assertStringContainsString('data-activate-session', $structureJs);
        $this->assertStringContainsString('data-promote-session', $structureJs);
        $this->assertStringContainsString('/promote', $structureJs);
        $this->assertStringContainsString('yearLive', $structureJs);
        $this->assertStringContainsString('data-seal-term', $structureJs);
        $this->assertStringContainsString('confirmDesk', $structureJs);
        $this->assertStringNotContainsString('window.confirm', $structureJs);
    }
}
