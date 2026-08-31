<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\RoleSlug;
use App\Models\AttendanceRecord;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AttendanceAuthorizationTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_class_teacher_can_mark_assigned_class_but_not_another_class(): void
    {
        $home = $this->offering();
        $other = $this->otherOffering($home);
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $staff = $this->staff($teacherUser);
        $this->classTeacher($staff, $home);

        $own = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']), $home);
        $otherEnrollment = $this->enroll($this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']), $other);

        $this->actingAs($teacherUser)->postJson('/api/v1/attendance', [
            'enrollment_id' => $own->id,
            'marked_on' => '2025-09-10',
            'status' => 'present',
        ])->assertCreated();

        $this->actingAs($teacherUser)->postJson('/api/v1/attendance', [
            'enrollment_id' => $otherEnrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'present',
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_subject_teacher_can_view_but_cannot_mark_daily_attendance(): void
    {
        $offering = $this->offering();
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $staff = $this->staff($teacherUser);
        $subjectOffering = $this->subjectOffering($offering);
        \App\Models\SubjectTeacherAssignment::query()->create([
            'staff_profile_id' => $staff->id,
            'subject_offering_id' => $subjectOffering->id,
            'is_active' => true,
            'assigned_on' => '2025-09-08',
        ]);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($teacherUser)->getJson('/api/v1/attendance/register?class_section_offering_id='.$offering->id.'&marked_on=2025-09-10')
            ->assertOk()
            ->assertJsonPath('data.can_mark', false);

        $this->actingAs($teacherUser)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'present',
        ])->assertForbidden();
    }

    public function test_staff_without_a_class_assignment_cannot_mark_attendance(): void
    {
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($teacherUser);
        $enrollment = $this->enroll($this->student(), $this->offering());

        $this->actingAs($teacherUser)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollment->id,
            'marked_on' => '2025-09-10',
            'status' => 'present',
        ])->assertForbidden();
    }

    public function test_parent_can_view_own_child_attendance_but_not_another_child(): void
    {
        $offering = $this->offering();
        $childA = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $childB = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']);
        $enrollmentA = $this->enroll($childA, $offering);
        $enrollmentB = $this->enroll($childB, $offering);
        $parentA = $this->userWithRole(RoleSlug::Parent);
        $parentB = $this->userWithRole(RoleSlug::Parent, ['email' => 'parent-b@school.test']);
        $this->linkGuardian($this->guardian($parentA, ['email' => $parentA->email]), $childA);
        $this->linkGuardian($this->guardian($parentB, ['full_name' => 'Mr. Okoro', 'email' => $parentB->email]), $childB);

        $recordA = AttendanceRecord::query()->create([
            'enrollment_id' => $enrollmentA->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Present,
        ]);
        $recordB = AttendanceRecord::query()->create([
            'enrollment_id' => $enrollmentB->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Absent,
        ]);

        $this->actingAs($parentA)->getJson('/api/v1/attendance/'.$recordA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $recordA->id);

        $this->actingAs($parentA)->getJson('/api/v1/attendance/'.$recordB->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');

        $this->actingAs($parentA)->getJson('/api/v1/attendance?student_profile_id='.$childB->id)
            ->assertForbidden();

        $this->actingAs($parentA)->getJson('/api/v1/attendance/summary?student_profile_id='.$childB->id)
            ->assertForbidden();

        $this->actingAs($parentA)->postJson('/api/v1/attendance', [
            'enrollment_id' => $enrollmentA->id,
            'marked_on' => '2025-09-11',
            'status' => 'present',
        ])->assertForbidden();
    }

    public function test_student_can_view_own_attendance_but_not_another_students(): void
    {
        $offering = $this->offering();
        $studentA = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $studentB = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']);
        $recordA = AttendanceRecord::query()->create([
            'enrollment_id' => $this->enroll($studentA, $offering)->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Present,
        ]);
        $recordB = AttendanceRecord::query()->create([
            'enrollment_id' => $this->enroll($studentB, $offering)->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Late,
        ]);

        $this->actingAs($studentA->user)->getJson('/api/v1/attendance/'.$recordA->id)->assertOk();
        $this->actingAs($studentA->user)->getJson('/api/v1/attendance/'.$recordB->id)->assertForbidden();
        $this->actingAs($studentA->user)->getJson('/api/v1/attendance')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recordA->id);
        $this->actingAs($studentA->user)->getJson('/api/v1/attendance/summary')
            ->assertOk()
            ->assertJsonPath('data.summary.present', 1);
    }

    public function test_unauthenticated_and_teacher_delete_attempts_fail(): void
    {
        $this->getJson('/api/v1/attendance')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');

        $offering = $this->offering();
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacherUser), $offering);
        $record = AttendanceRecord::query()->create([
            'enrollment_id' => $this->enroll($this->student(), $offering)->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Present,
        ]);

        $this->actingAs($teacherUser)->deleteJson('/api/v1/attendance/'.$record->id)
            ->assertForbidden();

        $this->actingAs($this->admin())->deleteJson('/api/v1/attendance/'.$record->id)
            ->assertOk();
    }

    public function test_teacher_cannot_update_another_class_mark(): void
    {
        $home = $this->offering();
        $other = $this->otherOffering($home);
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacherUser), $home);
        $record = AttendanceRecord::query()->create([
            'enrollment_id' => $this->enroll($this->student(), $other)->id,
            'class_section_offering_id' => $other->id,
            'marked_on' => '2025-09-10',
            'status' => AttendanceStatus::Absent,
        ]);

        $this->actingAs($teacherUser)->putJson('/api/v1/attendance/'.$record->id, [
            'status' => 'present',
            'correction_reason' => 'Should not work',
        ])->assertForbidden();
    }

    public function test_teacher_cannot_open_another_class_register(): void
    {
        $home = $this->offering();
        $other = $this->otherOffering($home);
        $teacherUser = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacherUser), $home);

        $this->actingAs($teacherUser)->getJson('/api/v1/attendance/register?class_section_offering_id='.$other->id.'&marked_on=2025-09-10')
            ->assertForbidden();
    }
}
