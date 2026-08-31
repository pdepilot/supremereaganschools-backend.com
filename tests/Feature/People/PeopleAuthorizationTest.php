<?php

namespace Tests\Feature\People;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PeopleAuthorizationTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_parent_can_access_own_child_but_not_another_parents_child(): void
    {
        $childA = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $childB = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0198',
            'surname' => 'Okoro',
            'first_name' => 'Daniel',
        ]);

        $parentA = $this->userWithRole(RoleSlug::Parent);
        $parentB = $this->userWithRole(RoleSlug::Parent, ['email' => 'parent-b@school.test']);
        $this->linkGuardian($this->guardian($parentA, ['email' => $parentA->email]), $childA);
        $this->linkGuardian($this->guardian($parentB, ['full_name' => 'Mr. Okoro', 'email' => $parentB->email]), $childB);

        $this->actingAs($parentA)->getJson('/api/v1/students/'.$childA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $childA->id);

        $this->actingAs($parentA)->getJson('/api/v1/me/children')
            ->assertOk()
            ->assertJsonPath('data.0.id', $childA->id);

        $this->actingAs($parentA)->getJson('/api/v1/students/'.$childB->id)
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_student_can_access_own_profile_but_not_another_students(): void
    {
        $studentA = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $studentB = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']);

        $this->actingAs($studentA->user)->getJson('/api/v1/students/'.$studentA->id)
            ->assertOk()
            ->assertJsonPath('data.admission_number', 'SRS/2025/0142')
            ->assertJsonMissingPath('data.password');

        $this->actingAs($studentA->user)->getJson('/api/v1/students/'.$studentB->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_teacher_cannot_perform_administrative_writes(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $staff = $this->staff($teacher);
        $student = $this->student();

        $this->actingAs($teacher)->putJson('/api/v1/students/'.$student->id, [
            'status' => 'inactive',
        ])->assertForbidden();

        $this->actingAs($teacher)->deleteJson('/api/v1/students/'.$student->id)
            ->assertForbidden();

        $this->actingAs($teacher)->postJson('/api/v1/students/'.$student->id.'/suspend')
            ->assertForbidden();

        $this->actingAs($teacher)->deleteJson('/api/v1/staff/'.$staff->id)
            ->assertForbidden();

        $this->actingAs($teacher)->postJson('/api/v1/staff/'.$staff->id.'/suspend')
            ->assertForbidden();
    }

    public function test_unauthenticated_people_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/students')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_sensitive_user_fields_are_not_serialized(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin())->getJson('/api/v1/students/'.$student->id)
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.user.password');
    }
}
