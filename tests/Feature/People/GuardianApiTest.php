<?php

namespace Tests\Feature\People;

use App\Enums\GuardianRelationship;
use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class GuardianApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_and_update_a_guardian(): void
    {
        $admin = $this->admin();

        $create = $this->actingAs($admin)->postJson('/api/v1/guardians', [
            'full_name' => 'Mrs. Okafor',
            'email' => 'okafor@school.test',
            'password' => 'password',
            'phone' => '08031110001',
            'occupation' => 'Trader',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.full_name', 'Mrs. Okafor')
            ->assertJsonPath('data.has_login', true)
            ->assertJsonMissingPath('data.password');

        $id = $create->json('data.id');

        $this->actingAs($admin)->putJson('/api/v1/guardians/'.$id, [
            'occupation' => 'Civil servant',
        ])
            ->assertOk()
            ->assertJsonPath('data.occupation', 'Civil servant');
    }

    public function test_guardian_can_be_linked_to_multiple_children_and_duplicates_are_rejected(): void
    {
        $admin = $this->admin();
        $first = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $second = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0198',
            'surname' => 'Okoro',
            'first_name' => 'Daniel',
            'gender' => \App\Enums\Gender::Male,
        ]);

        $create = $this->actingAs($admin)->postJson('/api/v1/guardians', [
            'full_name' => 'Mrs. Okafor',
            'email' => 'okafor@school.test',
            'password' => 'password',
            'student_profile_id' => $first->id,
            'relationship' => GuardianRelationship::Mother->value,
            'is_primary' => true,
        ]);

        $create->assertCreated();
        $guardianId = $create->json('data.id');

        $this->actingAs($admin)->postJson('/api/v1/guardians/'.$guardianId.'/students', [
            'student_profile_id' => $second->id,
            'relationship' => GuardianRelationship::Guardian->value,
            'is_primary' => true,
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/guardians/'.$guardianId.'/students', [
            'student_profile_id' => $first->id,
            'relationship' => GuardianRelationship::Mother->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('student_profile_id');

        $this->assertDatabaseCount('guardian_student', 2);
        $this->assertDatabaseHas('guardian_student', [
            'guardian_profile_id' => $guardianId,
            'student_profile_id' => $second->id,
            'is_primary' => 1,
        ]);
    }

    public function test_a_student_can_have_multiple_guardians(): void
    {
        $admin = $this->admin();
        $student = $this->student();

        $this->actingAs($admin)->postJson('/api/v1/guardians', [
            'full_name' => 'Mrs. Okafor',
            'phone' => '08031110001',
            'student_profile_id' => $student->id,
            'relationship' => GuardianRelationship::Mother->value,
            'is_primary' => true,
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/guardians', [
            'full_name' => 'Mr. Okafor',
            'phone' => '08031110002',
            'student_profile_id' => $student->id,
            'relationship' => GuardianRelationship::Father->value,
            'is_primary' => false,
        ])->assertCreated();

        $this->assertDatabaseCount('guardian_student', 2);
        $this->assertSame(1, \App\Models\GuardianStudent::query()->where('student_profile_id', $student->id)->where('is_primary', true)->count());
    }
}
