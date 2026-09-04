<?php

namespace Tests\Feature\People;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalPeoplePagesTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_people_pages_load_with_backend_hooks(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/students')
            ->assertOk()
            ->assertSee('data-page="students"', false)
            ->assertSee('data-pupil-table="nursery"', false)
            ->assertSee('data-pupil-table="primary"', false)
            ->assertSee('data-pupil-table="secondary"', false)
            ->assertSee('data-pupil-form', false)
            ->assertSee('data-pupil-form-title', false)
            ->assertSee('data-guardian-block', false)
            ->assertSee('id="pupilGuardianName"', false)
            ->assertSee('id="pupilGuardianRelation"', false)
            ->assertSee('id="pupilGuardianPhone"', false)
            ->assertSee('data-pupil-login-hint', false)
            ->assertSee('id="pupilPassword"', false)
            ->assertDontSee('name="password"', false)
            ->assertSee('id="pupilDob"', false)
            ->assertSee('data-origin-block', false)
            ->assertSee('data-health-block', false)
            ->assertSee('id="pupilAddress"', false)
            ->assertSee('data-photo-block', false)
            ->assertSee('id="pupilPhoto"', false)
            ->assertSee('data-pick-photo', false)
            ->assertSee('data-open-camera', false)
            ->assertSee('data-snap-photo', false)
            ->assertSee('data-pupil-photo-preview', false)
            ->assertSee('data-cancel-pupil-edit', false)
            ->assertSee('data-roll-copy', false)
            ->assertSee('data-pupil-fees', false)
            ->assertSee('Actions', false)
            ->assertSee('value="suspended"', false)
            ->assertSee('portal-people.js', false)
            ->assertSee('data-desk-alert', false)
            ->assertSee('desk-alert-card', false)
            ->assertSee('data-pupil-view', false)
            ->assertSee('data-print-pupil', false)
            ->assertSee('data-print-admission', false)
            ->assertSee('data-pupil-print-sheet', false)
            ->assertDontSee('1,248', false)
            ->assertDontSee('Owerri campus · 2025/2026', false)
            ->assertDontSee('Showing 5 of', false);

        $this->actingAs($admin)
            ->get('/portal/nursery')
            ->assertOk()
            ->assertSee('data-page="wing"', false)
            ->assertSee('data-pupil-table', false)
            ->assertSee('data-metric="pupils"', false)
            ->assertSee('data-pupil-view', false)
            ->assertSee('data-print-admission', false)
            ->assertSee('portal-people.js', false);

        $this->actingAs($admin)
            ->get('/portal/teachers')
            ->assertOk()
            ->assertSee('data-page="teachers"', false)
            ->assertSee('data-staff-table', false)
            ->assertSee('data-staff-form', false)
            ->assertSee('data-staff-form-title', false)
            ->assertSee('data-department-block', false)
            ->assertSee('data-add-department', false)
            ->assertSee('id="staffForm"', false)
            ->assertSee('Class teacher of', false)
            ->assertSee('data-form-block', false)
            ->assertSee('data-cancel-staff-edit', false)
            ->assertSee('data-desk-alert', false)
            ->assertSee('value="suspended"', false)
            ->assertSee('portal-people.js', false)
            ->assertDontSee('86 appointments', false);

        $peopleJs = (string) file_get_contents(public_path('site/JS/portal-people.js'));
        $this->assertStringContainsString('confirmDesk', $peopleJs);
        $this->assertStringContainsString('Remove from the roll', $peopleJs);
        $this->assertStringContainsString('data-edit-pupil', $peopleJs);
        $this->assertStringContainsString('data-view-pupil', $peopleJs);
        $this->assertStringContainsString('window.print', $peopleJs);
        $this->assertStringContainsString('/api/v1/students/', $peopleJs);
        $this->assertStringContainsString('is-printing-pupil', $peopleJs);
        $this->assertStringContainsString('data-print-admission', $peopleJs);
        $this->assertStringContainsString('data-suspend-staff', $peopleJs);
        $this->assertStringContainsString('data-edit-staff', $peopleJs);
        $this->assertStringContainsString('data-add-department', $peopleJs);
        $this->assertStringContainsString('/api/v1/departments', $peopleJs);
        $this->assertStringContainsString('class_section_offering_id', $peopleJs);
        $this->assertStringContainsString('Number(payload.class_section_offering_id)', $peopleJs);
        $this->assertStringContainsString(': null', $peopleJs);
        $this->assertStringContainsString('collectGuardian', $peopleJs);
        $this->assertStringContainsString('pupilPassword', $peopleJs);
        $this->assertStringContainsString('data-open-camera', $peopleJs);
        $this->assertStringContainsString('getUserMedia', $peopleJs);
        $this->assertStringNotContainsString('window.confirm', $peopleJs);
    }
}
