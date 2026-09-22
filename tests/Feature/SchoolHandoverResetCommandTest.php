<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\AcademicSession;
use App\Models\GuardianProfile;
use App\Models\Invoice;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class SchoolHandoverResetCommandTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_handover_reset_clears_operational_data_and_keeps_portal_admin(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession(['name' => '2026/2027']);
        $offering = $this->offering(session: $session);
        $student = $this->student();
        $this->enroll($student, $offering);
        $staff = $this->staff();
        $guardianUser = User::factory()->create();
        $guardian = GuardianProfile::query()->create([
            'user_id' => $guardianUser->id,
            'full_name' => 'Test Guardian',
            'phone' => '08031112233',
        ]);
        $guardianUser->assignRole(RoleSlug::Parent);

        $type = $this->feeType();
        Invoice::query()->create([
            'number' => 'INV-TEST-1',
            'student_profile_id' => $student->id,
            'academic_session_id' => $session->id,
            'term_id' => $this->termFor($session)->id,
            'status' => \App\Enums\InvoiceStatus::Unpaid,
            'total_kobo' => 100000,
            'paid_kobo' => 0,
            'due_on' => now()->toDateString(),
        ]);

        $this->artisan('school:handover-reset', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('academic_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('student_profiles', ['id' => $student->id]);
        $this->assertDatabaseMissing('staff_profiles', ['id' => $staff->id]);
        $this->assertDatabaseMissing('guardian_profiles', ['id' => $guardian->id]);
        $this->assertDatabaseMissing('invoices', ['number' => 'INV-TEST-1']);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertTrue($admin->fresh()->hasRole(RoleSlug::SchoolAdmin));
        $this->assertDatabaseHas('fee_types', ['id' => $type->id]);
    }
}
