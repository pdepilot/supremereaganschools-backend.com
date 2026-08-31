<?php

namespace Tests\Feature\Academic;

use App\Enums\RoleSlug;
use App\Models\SchoolSetting;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class SchoolSettingApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_authorized_admin_can_view_and_update_school_settings(): void
    {
        $this->settings([
            'name' => 'Supreme Reagan Schools',
            'address' => '15 Spibat Road',
            'phone' => '09065641343',
            'email' => 'supremereagansch@gmail.com',
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/api/v1/school-settings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'School settings retrieved.')
            ->assertJsonPath('data.name', 'Supreme Reagan Schools');

        $this->actingAs($admin)
            ->putJson('/api/v1/school-settings', [
                'name' => 'Supreme Reagan Schools Owerri',
                'motto' => 'Modeling excellence',
                'address' => '15 Spibat Road, Amakohia-Akwakuma, Owerri',
                'phone' => '09065641343',
                'email' => 'supremereagansch@gmail.com',
                'timezone' => 'Africa/Lagos',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Supreme Reagan Schools Owerri')
            ->assertJsonPath('data.motto', 'Modeling excellence');

        $this->assertDatabaseHas('school_settings', [
            'name' => 'Supreme Reagan Schools Owerri',
            'updated_by' => $admin->id,
        ]);
    }

    public function test_admin_can_update_office_hours_and_current_session_from_settings(): void
    {
        $session = $this->academicSession(['name' => '2025/2026']);
        $term = $this->termFor($session, ['name' => 'First Term', 'term_number' => 1]);
        $this->settings();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/api/v1/school-settings', [
                'name' => 'Supreme Reagan Schools',
                'office_opens_at' => '08:00',
                'office_closes_at' => '16:00',
                'current_academic_session_id' => $session->id,
                'current_term_id' => $term->id,
                'website' => '',
            ])
            ->assertOk()
            ->assertJsonPath('data.office_opens_at', '08:00')
            ->assertJsonPath('data.office_closes_at', '16:00')
            ->assertJsonPath('data.current_academic_session_id', $session->id)
            ->assertJsonPath('data.current_term_id', $term->id)
            ->assertJsonPath('data.website', null);
    }

    public function test_settings_update_validates_required_fields_and_relationships(): void
    {
        $this->settings();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/api/v1/school-settings', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'data', 'errors' => ['name']]);

        $this->actingAs($admin)
            ->putJson('/api/v1/school-settings', [
                'name' => 'Supreme Reagan Schools',
                'current_academic_session_id' => 999,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['current_academic_session_id']]);

        $session = $this->academicSession(['name' => '2025/2026']);
        $other = $this->academicSession(['name' => '2024/2025']);
        $term = $this->termFor($other, ['name' => 'First Term', 'term_number' => 1]);

        $this->actingAs($admin)
            ->putJson('/api/v1/school-settings', [
                'name' => 'Supreme Reagan Schools',
                'current_academic_session_id' => $session->id,
                'current_term_id' => $term->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['current_term_id']]);
    }

    public function test_unauthenticated_user_cannot_view_or_modify_settings(): void
    {
        $this->settings();

        $this->getJson('/api/v1/school-settings')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->putJson('/api/v1/school-settings', [
            'name' => 'Hacked',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_unauthorized_roles_cannot_modify_settings(): void
    {
        $this->settings();

        foreach ([RoleSlug::Teacher, RoleSlug::Parent, RoleSlug::Student] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->putJson('/api/v1/school-settings', [
                    'name' => 'Hacked',
                ])
                ->assertForbidden()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'This action is unauthorized.');
        }

        $this->assertDatabaseHas('school_settings', [
            'name' => 'Supreme Reagan Schools',
        ]);
    }

    public function test_missing_settings_row_returns_not_found_envelope(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/school-settings')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'School settings have not been created.');

        $this->assertSame(0, SchoolSetting::query()->count());
    }
}
