<?php

namespace Tests\Feature\Portal;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class ParentPagesTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function pages(): array
    {
        return [
            ['/parent/profile', 'parent_profile', 'data-profile-personal'],
            ['/parent/academics', 'parent_academics', 'data-results-body'],
            ['/parent/assignments', 'parent_assignments', 'data-assignment-list'],
            ['/parent/attendance', 'parent_attendance', 'data-attendance-body'],
            ['/parent/fees', 'parent_fees', 'data-fee-invoices'],
            ['/parent/timetable', 'parent_timetable', 'data-timetable-body'],
            ['/parent/materials', 'parent_materials', 'data-material-grid'],
            ['/parent/messages', 'parent_messages', 'data-conversation-items'],
            ['/parent/announcements', 'parent_announcements', 'data-notice-list'],
            ['/parent/settings', 'parent_settings', 'data-account-form'],
        ];
    }

    #[DataProvider('pages')]
    public function test_inner_family_pages_use_the_family_desk(string $path, string $page, string $hook): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $response = $this->actingAs($parent)
            ->get($path)
            ->assertOk()
            ->assertSee('data-page="'.$page.'"', false)
            ->assertSee('faculty-house', false)
            ->assertSee('staff-desk.css', false)
            ->assertSee('portal-parent-pages.js', false)
            ->assertSee($hook, false)
            ->assertSee('data-logout', false)
            ->assertSee('<strong>Log out</strong>', false)
            ->assertSee('href="/parent/profile"', false)
            ->assertSee('href="/parent/assignments"', false)
            ->assertDontSee('bootstrap.min.css', false)
            ->assertDontSee('parent_student.css', false)
            ->assertDontSee('Chiamaka Nwosu', false)
            ->assertDontSee('Mrs. Nwosu', false)
            ->assertDontSee('SRS/2025/0142', false)
            ->assertDontSee('Algebra Exercise', false)
            ->assertDontSee('82%', false)
            ->assertDontSee('Pay now', false);

        if ($page !== 'parent_settings') {
            $response->assertDontSee('type="password"', false);
        }
    }

    public function test_children_route_sends_the_parent_home(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($parent)
            ->get('/parent/children')
            ->assertRedirect('/parent');
    }

    public function test_guest_is_sent_to_parent_login(): void
    {
        $this->get('/parent/profile')->assertRedirect('/parent/login');
        $this->get('/parent/fees')->assertRedirect('/parent/login');
    }

    public function test_family_pages_script_reads_the_live_ledgers(): void
    {
        $js = (string) file_get_contents(public_path('site/JS/portal-parent-pages.js'));

        $this->assertStringContainsString('/api/v1/parent-desk', $js);
        $this->assertStringContainsString('student_profile_id=', $js);
        $this->assertStringContainsString('/api/v1/students/', $js);
        $this->assertStringContainsString('/api/v1/results', $js);
        $this->assertStringContainsString('term_id=', $js);
        $this->assertStringContainsString('data-term-select', $js);
        $this->assertStringContainsString('printResults', $js);
        $this->assertStringContainsString('srsTermReport', $js);
        $this->assertStringContainsString('row.scores', $js);
        $this->assertStringContainsString('assessment_types', $js);
        $this->assertStringContainsString('/api/v1/assignments', $js);
        $this->assertStringContainsString('/api/v1/attendance/summary', $js);
        $this->assertStringContainsString('/api/v1/me/fees/summary', $js);
        $this->assertStringContainsString('/api/v1/me/payments', $js);
        $this->assertStringContainsString('/api/v1/timetable?class_section_offering_id=', $js);
        $this->assertStringContainsString('/api/v1/learning-materials', $js);
        $this->assertStringContainsString('/api/v1/documents/', $js);
        $this->assertStringContainsString('/api/v1/announcements', $js);
        $this->assertStringContainsString('/api/v1/messages/recipients', $js);
        $this->assertStringContainsString('/api/v1/conversations', $js);
        $this->assertStringContainsString('/api/v1/me/password', $js);
        $this->assertStringContainsString('registered phone number', $js);
        $this->assertStringNotContainsString('/api/v1/payments/checkout', $js);
        $this->assertStringNotContainsString('blood_group', $js);
        $this->assertStringNotContainsString('genotype', $js);
        $this->assertStringNotContainsString('medical_notes', $js);
    }

    public function test_fees_page_does_not_offer_checkout(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($parent)
            ->get('/parent/fees')
            ->assertOk()
            ->assertSee('this desk does not take payment', false)
            ->assertDontSee('Pay now', false)
            ->assertDontSee('checkout', false);
    }

    public function test_settings_page_offers_the_optional_passphrase_door(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($parent)
            ->get('/parent/settings')
            ->assertOk()
            ->assertSee('data-password-form', false)
            ->assertSee('data-settings-copy', false)
            ->assertSee('type="password"', false);
    }

    public function test_unenrolled_child_gets_empty_results_not_a_missing_page(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);
        $guardian = $this->guardian($parent);
        $child = $this->student($this->userWithRole(RoleSlug::Student));
        $this->linkGuardian($guardian, $child);

        $this->actingAs($parent)
            ->getJson('/api/v1/results?student_profile_id='.$child->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.results', [])
            ->assertJsonPath('data.average', null)
            ->assertJsonPath('data.enrollment_id', null);
    }
}
