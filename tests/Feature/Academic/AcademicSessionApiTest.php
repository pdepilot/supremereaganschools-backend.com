<?php

namespace Tests\Feature\Academic;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AcademicSessionApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_list_and_retrieve_an_academic_session(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2026/2027',
                'starts_on' => '2026-09-07',
                'ends_on' => '2027-07-23',
                'term_count' => 3,
                'status' => 'planned',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', '2026/2027')
            ->assertJsonPath('data.status', 'planned')
            ->assertJsonCount(3, 'data.terms');

        $session = AcademicSession::query()->where('name', '2026/2027')->first();

        $this->actingAs($admin)
            ->getJson('/api/v1/academic-sessions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', '2026/2027');

        $this->actingAs($admin)
            ->getJson('/api/v1/academic-sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $session->id);
    }

    public function test_session_validation_rejects_bad_dates_and_duplicate_names(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['name', 'starts_on', 'ends_on', 'term_count']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2026/2027',
                'starts_on' => '2027-07-23',
                'ends_on' => '2026-09-07',
                'term_count' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['ends_on']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2025/2026',
                'starts_on' => '2025-09-08',
                'ends_on' => '2026-07-24',
                'term_count' => 3,
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2025/2026',
                'starts_on' => '2025-09-08',
                'ends_on' => '2026-07-24',
                'term_count' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_activating_a_session_archives_the_previous_active_session(): void
    {
        $this->settings();
        $admin = $this->admin();

        $first = $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2024/2025',
                'starts_on' => '2024-09-09',
                'ends_on' => '2025-07-25',
                'term_count' => 3,
                'status' => 'active',
            ])
            ->assertCreated()
            ->json('data');

        $second = $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2025/2026',
                'starts_on' => '2025-09-08',
                'ends_on' => '2026-07-24',
                'term_count' => 3,
                'status' => 'planned',
            ])
            ->json('data');

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions/'.$second['id'].'/activate')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('academic_sessions', [
            'id' => $first['id'],
            'status' => SessionStatus::Archived->value,
        ]);
        $this->assertDatabaseHas('academic_sessions', [
            'id' => $second['id'],
            'status' => SessionStatus::Active->value,
        ]);
        $this->assertDatabaseHas('school_settings', [
            'current_academic_session_id' => $second['id'],
            'current_term_id' => $second['terms'][0]['id'],
        ]);
        $this->assertDatabaseHas('terms', [
            'id' => $second['terms'][0]['id'],
            'status' => SessionStatus::Active->value,
        ]);
        $this->assertNotNull(AcademicSession::query()->find($first['id']));
    }

    public function test_archiving_the_current_session_clears_the_desk(): void
    {
        $this->settings();
        $admin = $this->admin();

        $session = $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2025/2026',
                'starts_on' => '2025-09-08',
                'ends_on' => '2026-07-24',
                'term_count' => 3,
                'status' => 'active',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertDatabaseHas('school_settings', [
            'current_academic_session_id' => $session['id'],
            'current_term_id' => $session['terms'][0]['id'],
        ]);

        $this->actingAs($admin)
            ->putJson('/api/v1/academic-sessions/'.$session['id'], [
                'status' => 'archived',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertNull(\App\Models\SchoolSetting::query()->value('current_academic_session_id'));
        $this->assertNull(\App\Models\SchoolSetting::query()->value('current_term_id'));
    }

    public function test_a_session_with_terms_cannot_be_deleted(): void
    {
        $admin = $this->admin();

        $session = $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2026/2027',
                'starts_on' => '2026-09-07',
                'ends_on' => '2027-07-23',
                'term_count' => 2,
            ])
            ->json('data');

        $this->actingAs($admin)
            ->deleteJson('/api/v1/academic-sessions/'.$session['id'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['session']]);

        $this->assertDatabaseHas('academic_sessions', ['id' => $session['id']]);
    }

    public function test_missing_session_returns_not_found_envelope(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/academic-sessions/9999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    public function test_unauthenticated_and_unauthorized_users_cannot_manage_sessions(): void
    {
        $this->postJson('/api/v1/academic-sessions', [
            'name' => '2026/2027',
            'starts_on' => '2026-09-07',
            'ends_on' => '2027-07-23',
            'term_count' => 3,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->actingAs($this->userWithRole(\App\Enums\RoleSlug::Teacher))
            ->postJson('/api/v1/academic-sessions', [
                'name' => '2026/2027',
                'starts_on' => '2026-09-07',
                'ends_on' => '2027-07-23',
                'term_count' => 3,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    }
}
