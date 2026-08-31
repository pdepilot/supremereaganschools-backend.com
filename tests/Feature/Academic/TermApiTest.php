<?php

namespace Tests\Feature\Academic;

use App\Enums\SessionStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class TermApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_a_term_belongs_to_exactly_one_session(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions/'.$session->id.'/terms', [
                'name' => 'First Term',
                'term_number' => 1,
                'starts_on' => '2025-09-08',
                'ends_on' => '2025-12-12',
                'status' => 'planned',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.academic_session_id', $session->id)
            ->assertJsonPath('data.name', 'First Term');

        $this->actingAs($admin)
            ->getJson('/api/v1/academic-sessions/'.$session->id.'/terms')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.academic_session_id', $session->id);
    }

    public function test_duplicate_term_number_and_name_within_a_session_are_rejected(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $this->termFor($session, ['name' => 'First Term', 'term_number' => 1]);

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions/'.$session->id.'/terms', [
                'name' => 'Opening Term',
                'term_number' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['term_number']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions/'.$session->id.'/terms', [
                'name' => 'First Term',
                'term_number' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_term_dates_must_fall_within_the_session_period(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession([
            'starts_on' => '2025-09-08',
            'ends_on' => '2026-07-24',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions/'.$session->id.'/terms', [
                'name' => 'First Term',
                'term_number' => 1,
                'starts_on' => '2025-08-01',
                'ends_on' => '2025-12-12',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['starts_on']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/academic-sessions/'.$session->id.'/terms', [
                'name' => 'First Term',
                'term_number' => 1,
                'starts_on' => '2025-09-08',
                'ends_on' => '2026-08-01',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['ends_on']]);
    }

    public function test_invalid_session_relationship_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/academic-sessions/9999/terms', [
                'name' => 'First Term',
                'term_number' => 1,
            ])
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    public function test_activating_a_term_clears_the_previous_active_term_in_the_same_session(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $first = $this->termFor($session, [
            'name' => 'First Term',
            'term_number' => 1,
            'status' => SessionStatus::Active,
        ]);
        $second = $this->termFor($session, [
            'name' => 'Second Term',
            'term_number' => 2,
            'status' => SessionStatus::Planned,
        ]);

        $this->actingAs($admin)
            ->putJson('/api/v1/terms/'.$second->id, [
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('terms', [
            'id' => $first->id,
            'status' => SessionStatus::Planned->value,
        ]);
    }

    public function test_sealing_a_term_makes_it_current_and_activates_its_session(): void
    {
        $this->settings();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Planned]);
        $term = $this->termFor($session, [
            'name' => 'First Term',
            'term_number' => 1,
            'status' => SessionStatus::Planned,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/terms/'.$term->id.'/activate')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Term sealed.')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('academic_sessions', [
            'id' => $session->id,
            'status' => SessionStatus::Active->value,
        ]);
        $this->assertDatabaseHas('school_settings', [
            'id' => 1,
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);
    }

    public function test_the_current_term_cannot_be_deleted(): void
    {
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $this->settings([
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson('/api/v1/terms/'.$term->id)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['term']]);
    }
}
