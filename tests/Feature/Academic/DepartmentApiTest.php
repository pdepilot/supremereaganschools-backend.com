<?php

namespace Tests\Feature\Academic;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class DepartmentApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_and_list_a_department(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/departments', [
                'name' => 'Guidance',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Guidance')
            ->assertJsonPath('data.is_active', true);

        $this->actingAs($admin)
            ->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Guidance');
    }

    public function test_duplicate_department_names_are_rejected(): void
    {
        $this->department(['name' => 'Guidance']);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/departments', [
                'name' => 'Guidance',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_teacher_cannot_create_a_department(): void
    {
        $this->actingAs($this->userWithRole(RoleSlug::Teacher))
            ->postJson('/api/v1/departments', [
                'name' => 'Hacked',
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }
}
