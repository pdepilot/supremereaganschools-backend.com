<?php

namespace Tests\Feature\Portal;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class ParentDeskApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_family_home_has_live_children_hooks_and_no_mock_copy(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($parent)
            ->get('/parent')
            ->assertOk()
            ->assertSee('data-page="parent-desk"', false)
            ->assertSee('faculty-house', false)
            ->assertSee('staff-desk.css', false)
            ->assertSee('portal-parent-desk.js', false)
            ->assertSee('data-children', false)
            ->assertSee('data-greeting', false)
            ->assertSee('data-logout', false)
            ->assertSee('<strong>Log out</strong>', false)
            ->assertSee('href="/parent/assignments"', false)
            ->assertDontSee('Mrs. Nwosu', false)
            ->assertDontSee('Amara Nwosu', false)
            ->assertDontSee('Kamsi Nwosu', false)
            ->assertDontSee('82%', false);

        $this->actingAs($parent)
            ->get('/parent/children')
            ->assertRedirect('/parent');

        $js = (string) file_get_contents(public_path('site/JS/portal-parent-desk.js'));
        $this->assertStringContainsString('/api/v1/parent-desk', $js);
        $this->assertStringContainsString('DESK_POLL_MS', $js);
        $this->assertStringContainsString('visibilitychange', $js);
        $this->assertStringContainsString('data-open-child', $js);
        $this->assertStringContainsString('/parent/profile', $js);
        $this->assertStringContainsString('srs.family.childId', $js);
    }

    public function test_empty_family_desk_returns_zero_children(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent, ['name' => 'Chinyere Okafor']);
        $this->guardian($parent, ['full_name' => 'Chinyere Okafor']);
        $this->settings(['name' => 'Supreme Reagan Schools']);

        $this->actingAs($parent)
            ->getJson('/api/v1/parent-desk')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Family desk retrieved.')
            ->assertJsonPath('data.name', 'Chinyere')
            ->assertJsonPath('data.full_name', 'Chinyere Okafor')
            ->assertJsonPath('data.metrics.children', 0)
            ->assertJsonPath('data.children', []);
    }

    public function test_family_desk_lists_children_the_guardian_may_open(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent, ['name' => 'Mrs. Okafor']);
        $guardian = $this->guardian($parent, ['full_name' => 'Mrs. Okafor']);
        $offering = $this->offering();
        $shown = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0142',
            'surname' => 'Okafor',
            'first_name' => 'Amara',
        ]);
        $hidden = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0198',
            'surname' => 'Okafor',
            'first_name' => 'Kamsi',
        ]);
        $this->enroll($shown, $offering);
        $this->linkGuardian($guardian, $shown);
        $this->linkGuardian($guardian, $hidden, ['is_primary' => false, 'can_login' => false]);

        $this->actingAs($parent)
            ->getJson('/api/v1/parent-desk')
            ->assertOk()
            ->assertJsonPath('data.metrics.children', 1)
            ->assertJsonPath('data.children.0.full_name', 'Okafor Amara')
            ->assertJsonPath('data.children.0.admission_number', 'SRS/2025/0142')
            ->assertJsonPath('data.children.0.form', $offering->classSection->name)
            ->assertJsonPath('data.children.0.class_section_offering_id', $offering->id);
    }

    public function test_admin_staff_and_pupil_cannot_read_the_family_desk(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/parent-desk')
            ->assertForbidden();

        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->actingAs($teacher)
            ->getJson('/api/v1/parent-desk')
            ->assertForbidden();

        $this->actingAs($this->userWithRole(RoleSlug::Student))
            ->getJson('/api/v1/parent-desk')
            ->assertForbidden();
    }

    public function test_guest_cannot_read_the_family_desk(): void
    {
        $this->getJson('/api/v1/parent-desk')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }
}
