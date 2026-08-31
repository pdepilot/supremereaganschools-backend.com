<?php

namespace Tests\Feature\Academic;

use App\Models\ClassSection;
use App\Models\Level;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AcademicDatabaseIntegrityTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_foreign_keys_prevent_deleting_a_level_that_still_has_classes(): void
    {
        $level = $this->level();
        $this->schoolClass($level);

        $this->expectException(QueryException::class);
        $level->delete();
    }

    public function test_foreign_keys_prevent_deleting_a_class_that_still_has_arms(): void
    {
        $class = $this->schoolClass();
        $this->section($class);

        $this->expectException(QueryException::class);
        $class->delete();
    }

    public function test_foreign_keys_prevent_deleting_a_session_that_still_has_terms(): void
    {
        $session = $this->academicSession();
        $this->termFor($session);

        $this->expectException(QueryException::class);
        $session->delete();
    }

    public function test_foreign_keys_prevent_deleting_an_arm_that_still_has_offerings(): void
    {
        $section = $this->section();
        $this->offering($section);

        $this->expectException(QueryException::class);
        $section->delete();
    }

    public function test_unique_constraints_prevent_duplicate_arms_inside_a_class(): void
    {
        $class = $this->schoolClass();
        $this->section($class, ['arm' => 'A', 'name' => 'JSS 1 A']);

        $this->expectException(QueryException::class);
        ClassSection::query()->create([
            'school_class_id' => $class->id,
            'arm' => 'A',
            'name' => 'JSS 1 A again',
            'is_active' => true,
        ]);
    }

    public function test_unique_constraints_prevent_duplicate_level_slugs(): void
    {
        $this->level(['name' => 'Nursery', 'slug' => 'nursery']);

        $this->expectException(QueryException::class);
        Level::query()->create([
            'name' => 'Early Years',
            'slug' => 'nursery',
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    public function test_an_arm_without_offerings_can_be_deleted_through_the_api(): void
    {
        $section = $this->section();

        $this->actingAs($this->admin())
            ->deleteJson('/api/v1/sections/'.$section->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('class_sections', ['id' => $section->id]);
    }
}
