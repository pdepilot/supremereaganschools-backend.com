<?php

namespace Tests\Feature\Academic;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class LevelClassSectionApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_a_level_and_duplicates_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/levels', [
                'name' => 'Nursery',
                'slug' => 'nursery',
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'nursery');

        $this->actingAs($admin)
            ->postJson('/api/v1/levels', [
                'name' => 'Nursery',
                'slug' => 'early-years',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['name']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/levels', [
                'name' => 'Primary',
                'slug' => 'nursery',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['slug']]);
    }

    public function test_a_class_belongs_to_a_level_and_rejects_invalid_or_duplicate_names(): void
    {
        $admin = $this->admin();
        $level = $this->level();

        $this->actingAs($admin)
            ->postJson('/api/v1/classes', [
                'level_id' => $level->id,
                'name' => 'JSS 1',
                'short_code' => 'J1',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.level_id', $level->id)
            ->assertJsonPath('data.name', 'JSS 1');

        $this->actingAs($admin)
            ->postJson('/api/v1/classes', [
                'level_id' => $level->id,
                'name' => 'JSS 1',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['name']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/classes', [
                'level_id' => 9999,
                'name' => 'JSS 2',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['level_id']]);
    }

    public function test_an_arm_belongs_to_a_class_and_duplicates_inside_that_class_are_rejected(): void
    {
        $admin = $this->admin();
        $class = $this->schoolClass();

        $this->actingAs($admin)
            ->postJson('/api/v1/classes/'.$class->id.'/sections', [
                'arm' => 'A',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.school_class_id', $class->id)
            ->assertJsonPath('data.arm', 'A')
            ->assertJsonPath('data.name', 'JSS 1 A');

        $this->actingAs($admin)
            ->postJson('/api/v1/classes/'.$class->id.'/sections', [
                'arm' => 'A',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['arm']]);

        $this->actingAs($admin)
            ->getJson('/api/v1/classes/'.$class->id.'/sections')
            ->assertOk()
            ->assertJsonPath('data.0.arm', 'A');
    }

    public function test_invalid_class_relationship_for_an_arm_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/classes/9999/sections', [
                'arm' => 'A',
            ])
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_a_level_with_classes_cannot_be_deleted(): void
    {
        $level = $this->level();
        $this->schoolClass($level);

        $this->actingAs($this->admin())
            ->deleteJson('/api/v1/levels/'.$level->id)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['level']]);

        $this->assertDatabaseHas('levels', ['id' => $level->id]);
    }

    public function test_a_class_with_arms_cannot_be_deleted(): void
    {
        $class = $this->schoolClass();
        $this->section($class);

        $this->actingAs($this->admin())
            ->deleteJson('/api/v1/classes/'.$class->id)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['class']]);
    }

    public function test_missing_class_returns_not_found_envelope(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/classes/9999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The requested resource was not found.');
    }
}
