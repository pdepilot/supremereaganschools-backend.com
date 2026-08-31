<?php

namespace Tests\Feature\Portal;

use App\Enums\AttendanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\RoleSlug;
use App\Models\AttendanceRecord;
use App\Models\Invoice;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class LevelDeskApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_nursery_desk_counts_only_nursery_pupils_and_forms(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $campus = $this->campus();
        $this->settings(['current_academic_session_id' => $session->id]);

        $nurseryLevel = $this->level(['name' => 'Nursery', 'slug' => 'nursery', 'sort_order' => 1]);
        $nurseryOffering = $this->offering(
            $this->section($this->schoolClass($nurseryLevel, ['name' => 'KG 1', 'short_code' => 'KG1'])),
            $session,
            $campus,
        );
        $secondaryOffering = $this->offering(null, $session, $campus);

        $nurseryPupil = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0101']);
        $secondaryPupil = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0202']);
        $nurseryEnrollment = $this->enroll($nurseryPupil, $nurseryOffering);
        $this->enroll($secondaryPupil, $secondaryOffering);

        Invoice::query()->create([
            'number' => 'INV/2025/0301',
            'student_profile_id' => $nurseryPupil->id,
            'enrollment_id' => $nurseryEnrollment->id,
            'academic_session_id' => $session->id,
            'term_id' => $this->termFor($session)->id,
            'status' => InvoiceStatus::Unpaid,
            'total_kobo' => 500000,
            'paid_kobo' => 0,
        ]);

        AttendanceRecord::query()->create([
            'enrollment_id' => $nurseryEnrollment->id,
            'class_section_offering_id' => $nurseryOffering->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Present,
            'marked_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/level-desks/nursery')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'nursery')
            ->assertJsonPath('data.name', 'Nursery')
            ->assertJsonPath('data.metrics.pupils', 1)
            ->assertJsonPath('data.metrics.forms', 1)
            ->assertJsonPath('data.metrics.attendance_percent', 100)
            ->assertJsonPath('data.metrics.outstanding', '₦5,000');

        $this->actingAs($admin)
            ->getJson('/api/v1/level-desks/secondary')
            ->assertOk()
            ->assertJsonPath('data.metrics.pupils', 1)
            ->assertJsonPath('data.metrics.outstanding', '₦0');

        $this->actingAs($admin)
            ->getJson('/api/v1/level-desks/college')
            ->assertNotFound();
    }

    public function test_teacher_cannot_read_a_level_desk(): void
    {
        $this->actingAs($this->userWithRole(RoleSlug::Teacher))
            ->getJson('/api/v1/level-desks/nursery')
            ->assertForbidden();
    }
}
