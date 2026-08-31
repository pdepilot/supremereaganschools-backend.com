<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\RoleSlug;
use App\Models\AttendanceRecord;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AttendanceBulkTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_bulk_marking_records_the_whole_class_atomically(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $first = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $offering);
        $second = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $offering);

        $this->actingAs($admin)->postJson('/api/v1/attendance/bulk', [
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2025-09-10',
            'records' => [
                ['enrollment_id' => $first->id, 'status' => 'present'],
                ['enrollment_id' => $second->id, 'status' => 'late', 'remark' => 'Arrived 8:15'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('attendance_records', 2);
        $this->assertDatabaseHas('attendance_records', [
            'enrollment_id' => $second->id,
            'status' => AttendanceStatus::Late->value,
        ]);
    }

    public function test_bulk_rejects_a_pupil_from_another_class_and_writes_nothing(): void
    {
        $admin = $this->admin();
        $home = $this->offering();
        $other = $this->otherOffering($home);
        $own = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $home);
        $stranger = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $other);

        $this->actingAs($admin)->postJson('/api/v1/attendance/bulk', [
            'class_section_offering_id' => $home->id,
            'marked_on' => '2025-09-10',
            'records' => [
                ['enrollment_id' => $own->id, 'status' => 'present'],
                ['enrollment_id' => $stranger->id, 'status' => 'present'],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_bulk_updates_existing_marks_when_a_correction_reason_is_supplied(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $enrollment = $this->enroll($this->student(), $offering);

        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Absent,
            'marked_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/attendance/bulk', [
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2025-09-10',
            'correction_reason' => 'Updated from the class register.',
            'records' => [
                ['enrollment_id' => $enrollment->id, 'status' => 'present'],
            ],
        ])->assertOk()->assertJsonPath('data.0.status', 'present');

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_corrections', [
            'from_status' => 'absent',
            'to_status' => 'present',
            'reason' => 'Updated from the class register.',
            'corrected_by' => $admin->id,
        ]);
    }

    public function test_student_summary_counts_and_percentage_are_correct(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $enrollment = $this->enroll($student, $this->offering());

        foreach (['present', 'present', 'late', 'absent'] as $i => $status) {
            AttendanceRecord::query()->create([
                'enrollment_id' => $enrollment->id,
                'class_section_offering_id' => $enrollment->class_section_offering_id,
                'marked_on' => sprintf('2025-09-%02d', 10 + $i),
                'status' => $status,
                'marked_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)->getJson('/api/v1/attendance/summary?student_profile_id='.$student->id)
            ->assertOk()
            ->assertJsonPath('data.summary.recorded', 4)
            ->assertJsonPath('data.summary.present', 2)
            ->assertJsonPath('data.summary.late', 1)
            ->assertJsonPath('data.summary.absent', 1)
            ->assertJsonPath('data.summary.percentage', 75);
    }

    public function test_class_summary_counts_pupils_on_roll_and_marks(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $first = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $offering);
        $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $offering);

        AttendanceRecord::query()->create([
            'enrollment_id' => $first->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Present,
            'marked_by' => $admin->id,
        ]);

        $this->actingAs($admin)->getJson('/api/v1/attendance/summary?class_section_offering_id='.$offering->id.'&marked_on=2025-09-10')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.recorded', 1)
            ->assertJsonPath('data.present', 1)
            ->assertJsonPath('data.percentage', 50);
    }
}
