<?php

namespace Tests\Feature\People;

use App\Models\GuardianProfile;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PeopleDatabaseIntegrityTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_staff_number_must_be_unique(): void
    {
        $this->staff(null, ['staff_number' => 'SRS/TCH/0012']);

        $this->expectException(QueryException::class);

        StaffProfile::query()->create([
            'user_id' => $this->userWithRole(\App\Enums\RoleSlug::Teacher)->id,
            'staff_number' => 'SRS/TCH/0012',
            'status' => \App\Enums\StaffStatus::Active,
        ]);
    }

    public function test_admission_number_must_be_unique(): void
    {
        $this->student(null, ['admission_number' => 'SRS/2025/0142']);

        $this->expectException(QueryException::class);

        StudentProfile::query()->create([
            'user_id' => $this->userWithRole(\App\Enums\RoleSlug::Student)->id,
            'admission_number' => 'SRS/2025/0142',
            'surname' => 'Other',
            'first_name' => 'Pupil',
            'gender' => \App\Enums\Gender::Male,
            'status' => \App\Enums\StudentStatus::Active,
        ]);
    }

    public function test_duplicate_guardian_student_pairs_are_rejected(): void
    {
        $student = $this->student();
        $guardian = $this->guardian();
        $this->linkGuardian($guardian, $student);

        $this->expectException(QueryException::class);

        $this->linkGuardian($guardian, $student, ['is_primary' => false]);
    }

    public function test_removing_an_enrolled_pupil_soft_deletes_and_keeps_enrollment_history(): void
    {
        $student = $this->student();
        $enrollment = $this->enroll($student);

        $this->actingAs($this->admin())->deleteJson('/api/v1/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('message', 'Pupil removed.');

        $this->assertSoftDeleted('student_profiles', ['id' => $student->id]);
        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'student_profile_id' => $student->id,
        ]);

        $this->expectException(QueryException::class);
        StudentProfile::withTrashed()->findOrFail($student->id)->forceDelete();
    }

    public function test_guardians_cannot_be_deleted_while_linked(): void
    {
        $guardian = $this->guardian();
        $this->linkGuardian($guardian, $this->student());

        $this->actingAs($this->admin())->deleteJson('/api/v1/guardians/'.$guardian->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guardian');

        $this->assertNotNull(GuardianProfile::query()->find($guardian->id));
    }
}
