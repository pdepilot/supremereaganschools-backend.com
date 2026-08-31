<?php

namespace Tests\Feature\Admissions;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalInboxPageTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_inbox_page_loads_with_backend_hooks(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/messages')
            ->assertOk()
            ->assertSee('data-page="messages"', false)
            ->assertSee('data-inbox-urgent', false)
            ->assertSee('data-inbox-watch', false)
            ->assertSee('data-inbox-cleared', false)
            ->assertSee('data-inbox-letter', false)
            ->assertSee('data-inbox-clear-urgent', false)
            ->assertSee('data-inbox-clear-letter', false)
            ->assertSee('data-metric="unread"', false)
            ->assertSee('data-desk-alert', false)
            ->assertSee('portal-inbox.js', false)
            ->assertDontSee('Mrs. Ngozi Eze', false)
            ->assertDontSee('Mr. Ikenna Uche', false)
            ->assertDontSee('PTA Secretariat', false)
            ->assertDontSee('Mrs. Amaka', false)
            ->assertDontSee('Mr. Daniel Okoro', false)
            ->assertDontSee('Mrs. Kalu', false)
            ->assertDontSee('data-count="11"', false)
            ->assertDontSee('data-count="3"', false)
            ->assertDontSee('data-count="8"', false)
            ->assertDontSee('data-count="14"', false);

        $js = (string) file_get_contents(public_path('site/JS/portal-inbox.js'));
        $this->assertStringContainsString('/api/v1/inbox', $js);
        $this->assertStringContainsString('/api/v1/inbox/open', $js);
        $this->assertStringContainsString('/api/v1/inbox/clear-urgent', $js);
        $this->assertStringContainsString('/api/v1/contact-enquiries/', $js);
        $this->assertStringContainsString('confirmDesk', $js);
        $this->assertStringContainsString('escapeHtml', $js);
        $this->assertStringContainsString('INBOX_POLL_MS', $js);
        $this->assertStringContainsString('visibilitychange', $js);
    }

    public function test_parents_cannot_open_the_inbound_chute(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($parent)
            ->get('/portal/messages')
            ->assertRedirect(route('parent.home'));
    }
}
