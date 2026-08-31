<?php

namespace Tests\Feature\Academic;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AcademicAuthorizationTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->settings();
        $this->level();
    }

    /**
     * @return list<RoleSlug>
     */
    private function blockedRoles(): array
    {
        return [RoleSlug::Teacher, RoleSlug::Parent, RoleSlug::Student];
    }

    /**
     * @return list<string>
     */
    private function protectedGets(): array
    {
        return [
            '/api/v1/school-settings',
            '/api/v1/academic-sessions',
            '/api/v1/levels',
            '/api/v1/classes',
            '/api/v1/subjects',
            '/api/v1/class-section-offerings',
            '/api/v1/subject-offerings',
            '/api/v1/campuses',
            '/api/v1/departments',
        ];
    }

    public function test_school_admin_and_super_admin_can_read_academic_structure(): void
    {
        foreach ([RoleSlug::SchoolAdmin, RoleSlug::SuperAdmin] as $role) {
            $user = $this->userWithRole($role);

            foreach ($this->protectedGets() as $url) {
                $this->actingAs($user)
                    ->getJson($url)
                    ->assertOk()
                    ->assertJsonPath('success', true);
            }
        }
    }

    public function test_teachers_parents_and_students_cannot_read_or_write_academic_structure(): void
    {
        foreach ($this->blockedRoles() as $role) {
            $user = $this->userWithRole($role);

            foreach ($this->protectedGets() as $url) {
                $this->actingAs($user)
                    ->getJson($url)
                    ->assertForbidden()
                    ->assertJsonPath('success', false)
                    ->assertJsonPath('message', 'This action is unauthorized.');
            }

            $this->actingAs($user)
                ->postJson('/api/v1/levels', [
                    'name' => 'Hacked',
                    'slug' => 'hacked',
                ])
                ->assertForbidden()
                ->assertJsonPath('success', false);
        }
    }

    public function test_unauthenticated_requests_are_rejected_with_the_api_envelope(): void
    {
        $this->getJson('/api/v1/levels')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->postJson('/api/v1/subjects', [
            'name' => 'Mathematics',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }
}
