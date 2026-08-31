<?php

namespace Tests\Feature\Portal;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalFeesPageTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_fees_page_has_live_hooks_and_no_mock_copy(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/fees')
            ->assertOk()
            ->assertSee('data-page="fees"', false)
            ->assertSee('data-metric="expected"', false)
            ->assertSee('data-payment-form', false)
            ->assertSee('data-fee-form', false)
            ->assertSee('data-fee-table', false)
            ->assertSee('data-receipt-list', false)
            ->assertSee('data-raise-invoices', false)
            ->assertSee('data-invoice-table', false)
            ->assertSee('data-invoice-root', false)
            ->assertSee('portal-fees.js', false)
            ->assertSee('data-desk-alert', false)
            ->assertDontSee('data-count="62.4"', false)
            ->assertDontSee('data-count="48.6"', false)
            ->assertDontSee('data-count="13.8"', false)
            ->assertDontSee('78% of the book', false)
            ->assertDontSee("This morning’s posts", false)
            ->assertDontSee('placeholder="SRS/2025/0142"', false)
            ->assertDontSee('placeholder="185000"', false);

        $feesJs = (string) file_get_contents(public_path('site/JS/portal-fees.js'));
        $this->assertStringContainsString('[data-payment-form]', $feesJs);
        $this->assertStringContainsString('[data-fee-form]', $feesJs);
        $this->assertStringContainsString('/api/v1/fee-types', $feesJs);
        $this->assertStringContainsString('/api/v1/fee-structures', $feesJs);
        $this->assertStringContainsString('[data-invoice-table]', $feesJs);
        $this->assertStringContainsString('/api/v1/invoices/summary', $feesJs);
        $this->assertStringContainsString('/api/v1/invoices/generate', $feesJs);
        $this->assertStringContainsString('confirmDesk', $feesJs);
    }
}
