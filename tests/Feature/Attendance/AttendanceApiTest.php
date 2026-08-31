<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\RoleSlug;
use App\Models\AttendanceRecord;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AttendanceApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_mark_present_absent_and_late(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $first = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $offering);
        $second = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $offering);
        $third = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0221']), $offering);

        $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $first->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Present->value,
        ])->assertCreated()->assertJsonPath('data.status', 'present')->assertJsonPath('success', true);

        $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $second->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Absent->value,
            'remark' => 'Unwell',
        ])->assertCreated()->assertJsonPath('data.status', 'absent');

        $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $third->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Late->value,
        ])->assertCreated()->assertJsonPath('data.status', 'late');
    }

    public function test_duplicate_attendance_for_the_same_enrollment_and_date_is_rejected(): void
    {
        $admin = $this->admin();
        $enrollment = $this->enroll($this->student(), $this->offering());

        $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'present',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'absent',
        ])->assertUnprocessable()->assertJsonValidationErrors('marked_on');
    }

    public function test_invalid_status_and_future_dates_are_rejected(): void
    {
        $admin = $this->admin();
        $enrollment = $this->enroll($this->student(), $this->offering());

        $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'excused',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2029-09-10',
            'status' => 'present',
        ])->assertUnprocessable()->assertJsonValidationErrors('marked_on');
    }

    public function test_attendance_before_enrollment_or_outside_the_session_is_rejected(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $offering = $this->offering(null, $session);
        $enrollment = $this->enroll($this->student(), $offering, ['enrolled_on' => '2025-10-01']);

        $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'present',
        ])->assertUnprocessable()->assertJsonValidationErrors('marked_on');

        $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2026-08-01',
            'status' => 'present',
        ])->assertUnprocessable()->assertJsonValidationErrors('marked_on');
    }

    public function test_attendance_can_be_corrected_with_an_auditable_reason(): void
    {
        $admin = $this->admin();
        $enrollment = $this->enroll($this->student(), $this->offering());

        $created = $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'absent',
        ])->assertCreated();

        $this->actingAs($admin)->putJson('/api/v1/attendance/'.$created->json('data.id'), [
            'status' => 'present',
            'correction_reason' => 'Parent brought a medical note.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.corrections.0.from_status', 'absent')
            ->assertJsonPath('data.corrections.0.to_status', 'present')
            ->assertJsonPath('data.corrections.0.reason', 'Parent brought a medical note.')
            ->assertJsonPath('data.corrections.0.corrected_by', $admin->name);

        $this->actingAs($admin)->putJson('/api/v1/attendance/'.$created->json('data.id'), [
            'status' => 'late',
        ])->assertUnprocessable()->assertJsonValidationErrors('correction_reason');
    }

    public function test_remark_only_update_does_not_require_a_correction_reason(): void
    {
        $admin = $this->admin();
        $enrollment = $this->enroll($this->student(), $this->offering());

        $created = $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'present',
        ])->assertCreated();

        $this->actingAs($admin)->putJson('/api/v1/attendance/'.$created->json('data.id'), [
            'remark' => 'Arrived with a note.',
        ])->assertOk()->assertJsonPath('data.remark', 'Arrived with a note.');
    }

    public function test_missing_attendance_returns_the_established_404_envelope(): void
    {
        $this->actingAs($this->admin())->getJson('/api/v1/attendance/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    public function test_attendance_with_corrections_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $enrollment = $this->enroll($this->student(), $this->offering());

        $created = $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'absent',
        ])->assertCreated();

        $this->actingAs($admin)->putJson('/api/v1/attendance/'.$created->json('data.id'), [
            'status' => 'present',
            'correction_reason' => 'Medical note received.',
        ])->assertOk();

        $this->actingAs($admin)->deleteJson('/api/v1/attendance/'.$created->json('data.id'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attendance');
    }

    public function test_database_unique_constraint_blocks_duplicate_marks(): void
    {
        $enrollment = $this->enroll($this->student(), $this->offering());

        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $enrollment->class_section_offering_id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Present,
            'marked_by' => $this->admin()->id,
        ]);

        $this->expectException(QueryException::class);

        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $enrollment->class_section_offering_id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Absent,
            'marked_by' => $this->admin()->id,
        ]);
    }

    public function test_enrollment_cannot_be_hard_deleted_while_attendance_exists(): void
    {
        $enrollment = $this->enroll($this->student(), $this->offering());
        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $enrollment->class_section_offering_id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Present,
        ]);

        $this->expectException(QueryException::class);
        $enrollment->delete();
    }

    public function test_marked_by_cannot_be_mass_assigned_from_the_request(): void
    {
        $admin = $this->admin();
        $stranger = $this->userWithRole(RoleSlug::Teacher);
        $enrollment = $this->enroll($this->student(), $this->offering());

        $this->actingAs($admin)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'present',
            'marked_by' => $stranger->id,
        ])->assertCreated()->assertJsonPath('data.marked_by', $admin->name);

        $this->assertDatabaseHas('attendance_records', [
            'enrollment_id' => $enrollment->id,
            'marked_by' => $admin->id,
        ]);
    }
}
