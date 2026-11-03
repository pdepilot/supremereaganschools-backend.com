<?php

namespace Tests\Feature\Attendance;

use App\Enums\RoleSlug;
use App\Enums\StaffAttendanceStatus;
use App\Models\Campus;
use App\Models\StaffAttendanceAdjustment;
use App\Models\StaffAttendancePolicy;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffAttendanceVerificationLog;
use App\Models\StaffCampusAssignment;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Attendance\GeofenceService;
use App\Services\Attendance\StaffAttendanceService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class StaffGpsAttendanceTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected Campus $primaryCampus;

    protected Campus $secondaryCampus;

    protected StaffProfile $staffMember;

    protected User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Configure Primary Campus (Owerri Main)
        $this->primaryCampus = Campus::query()->create([
            'name' => 'Primary School Campus',
            'code' => 'PRI-CAMPUS',
            'address' => '10 Spibat Road, Owerri',
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'geofence_type' => 'circle',
            'radius_meters' => 100,
            'gps_accuracy_threshold' => 50,
            'operating_hours_start' => '06:30:00',
            'operating_hours_end' => '18:30:00',
            'expected_clock_in_time' => '07:45:00',
            'expected_clock_out_time' => '16:00:00',
            'grace_period_minutes' => 15,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'is_active' => true,
        ]);

        // Configure Secondary Campus (down the same street, 250m away)
        $this->secondaryCampus = Campus::query()->create([
            'name' => 'Secondary School Campus',
            'code' => 'SEC-CAMPUS',
            'address' => '25 Spibat Road, Owerri',
            'latitude' => 5.4872000,
            'longitude' => 7.0368000,
            'geofence_type' => 'circle',
            'radius_meters' => 80,
            'gps_accuracy_threshold' => 45,
            'operating_hours_start' => '06:30:00',
            'operating_hours_end' => '18:30:00',
            'expected_clock_in_time' => '07:45:00',
            'expected_clock_out_time' => '16:00:00',
            'grace_period_minutes' => 15,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'is_active' => true,
        ]);

        $this->staffUser = $this->userWithRole(RoleSlug::Teacher, ['name' => 'John Doe', 'email' => 'john.doe@srs.edu']);
        $this->staffMember = $this->staff($this->staffUser);

        // Assign staff to Primary Campus as primary
        StaffCampusAssignment::query()->create([
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->primaryCampus->id,
            'is_primary' => true,
            'start_date' => '2026-01-01',
            'end_date' => null,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_staff_can_clock_in_within_geofence_boundary(): void
    {
        // Monday at 07:30 AM in Lagos timezone
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851250, // ~0.25 meters away from campus center
            'longitude' => 7.0354140,
            'accuracy' => 12.5,
            'device' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.campus_name', 'Primary School Campus');

        $record = StaffAttendanceRecord::query()->where('staff_profile_id', $this->staffMember->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals(StaffAttendanceStatus::Present, $record->status);
        $this->assertEquals($this->primaryCampus->id, $record->campus_id);

        $this->assertDatabaseHas('staff_attendance_verification_logs', [
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->primaryCampus->id,
            'action' => 'clock_in',
            'result' => 'accepted',
            'is_within_boundary' => true,
            'accuracy_acceptable' => true,
        ]);
    }

    public function test_staff_clock_in_rejected_when_outside_geofence_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Location is ~1.5 km away from primary campus
        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4950000,
            'longitude' => 7.0450000,
            'accuracy' => 15.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['location']);

        $record = StaffAttendanceRecord::query()->where('staff_profile_id', $this->staffMember->id)->first();
        $this->assertNull($record);

        $this->assertDatabaseHas('staff_attendance_verification_logs', [
            'staff_profile_id' => $this->staffMember->id,
            'action' => 'clock_in',
            'result' => 'rejected',
            'is_within_boundary' => false,
        ]);
    }

    public function test_staff_clock_in_rejected_when_gps_accuracy_exceeds_threshold(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Close to campus center, but device accuracy is 85 meters (> 50m threshold)
        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 85.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['location']);

        $this->assertDatabaseHas('staff_attendance_verification_logs', [
            'staff_profile_id' => $this->staffMember->id,
            'action' => 'clock_in',
            'result' => 'rejected',
            'accuracy_acceptable' => false,
        ]);
    }

    public function test_multi_campus_staff_can_select_and_clock_in_at_either_campus(): void
    {
        // Assign staff to Secondary campus as well (multi-campus assignment)
        StaffCampusAssignment::query()->create([
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->secondaryCampus->id,
            'is_primary' => false,
            'start_date' => '2026-01-01',
            'end_date' => null,
            'is_active' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Staff is physically present at the Secondary campus
        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->secondaryCampus->id,
            'latitude' => 5.4872010, // at secondary campus coordinates
            'longitude' => 7.0368020,
            'accuracy' => 10.0,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.campus_name', 'Secondary School Campus');

        $record = StaffAttendanceRecord::query()->where('staff_profile_id', $this->staffMember->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals(StaffAttendanceStatus::Present, $record->status);
        $this->assertEquals($this->secondaryCampus->id, $record->campus_id);
    }

    public function test_staff_clock_in_rejected_for_unassigned_campus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Staff is NOT assigned to secondary campus
        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->secondaryCampus->id,
            'latitude' => 5.4872000,
            'longitude' => 7.0368000,
            'accuracy' => 10.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['campus_id']);
    }

    public function test_staff_marked_late_when_clocking_in_after_grace_period(): void
    {
        // Monday at 08:10 AM in Lagos (cutoff: 07:45 + 15m = 08:00 AM)
        Carbon::setTestNow(Carbon::parse('2026-11-09 08:10:00', 'Africa/Lagos'));

        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 15.0,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'late');

        $record = StaffAttendanceRecord::query()->where('staff_profile_id', $this->staffMember->id)->first();
        $this->assertEquals(StaffAttendanceStatus::Late, $record->status);
    }

    public function test_duplicate_clock_in_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // First clock-in
        $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 10.0,
        ])->assertCreated();

        // Second clock-in attempt
        $second = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 10.0,
        ]);

        $second->assertStatus(422)
            ->assertJsonValidationErrors(['attendance']);
    }

    public function test_clock_out_successful_inside_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 10.0,
        ])->assertCreated();

        // Advance to 16:15 PM in Lagos
        Carbon::setTestNow(Carbon::parse('2026-11-09 16:15:00', 'Africa/Lagos'));

        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-out', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 12.0,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'present');

        $record = StaffAttendanceRecord::query()->where('staff_profile_id', $this->staffMember->id)->first();
        $this->assertNotNull($record->clock_out_at);
        $this->assertEquals(525, $record->durationMinutes()); // 8 hours 45 mins = 525 mins
    }

    public function test_clock_out_rejected_when_outside_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 10.0,
        ])->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-11-09 16:15:00', 'Africa/Lagos'));

        // 3 km away from campus
        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-out', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.5100000,
            'longitude' => 7.0500000,
            'accuracy' => 10.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['location']);

        $record = StaffAttendanceRecord::query()->where('staff_profile_id', $this->staffMember->id)->first();
        $this->assertNull($record->clock_out_at);
    }

    public function test_clock_out_rejected_without_prior_clock_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 16:15:00', 'Africa/Lagos'));

        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-out', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 10.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['attendance']);
    }

    public function test_clock_in_rejected_for_expired_campus_assignment(): void
    {
        // Expire assignment
        StaffCampusAssignment::query()->where('staff_profile_id', $this->staffMember->id)->update([
            'end_date' => '2026-10-01', // ended last month
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 10.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['campus_id']);
    }

    public function test_clock_in_rejected_for_inactive_campus(): void
    {
        $this->primaryCampus->update(['is_active' => false]);

        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 10.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['campus_id']);
    }

    public function test_anti_spoofing_flags_unnaturally_precise_gps_readings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Accuracy is 0.01 meters (impossible for phone GPS, typical of mock location apps)
        $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 0.01,
        ])->assertCreated();

        $record = StaffAttendanceRecord::query()->where('staff_profile_id', $this->staffMember->id)->first();
        $this->assertTrue($record->is_flagged);

        $this->assertDatabaseHas('staff_attendance_verification_logs', [
            'staff_profile_id' => $this->staffMember->id,
            'is_suspicious' => true,
        ]);
    }

    public function test_staff_can_request_attendance_correction_and_admin_can_approve(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        $record = StaffAttendanceRecord::query()->create([
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->primaryCampus->id,
            'date' => '2026-11-09',
            'clock_in_at' => Carbon::parse('2026-11-09 08:30:00', 'Africa/Lagos'),
            'clock_out_at' => null,
            'status' => StaffAttendanceStatus::Late,
        ]);

        // Staff submits correction
        $requestResponse = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/correction', [
            'record_id' => $record->id,
            'requested_clock_in' => '2026-11-09 07:40:00',
            'requested_status' => 'present',
            'reason' => 'Network error delayed submission during official morning assembly duty.',
        ]);

        $requestResponse->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $adjustment = StaffAttendanceAdjustment::query()->first();
        $this->assertNotNull($adjustment);

        // SuperAdmin approves correction
        $admin = $this->userWithRole(RoleSlug::SuperAdmin);

        $reviewResponse = $this->actingAs($admin)->postJson("/api/v1/admin/staff-attendance/adjustments/{$adjustment->id}/review", [
            'approve' => true,
            'review_notes' => 'Confirmed with Vice Principal.',
        ]);

        $reviewResponse->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $record->refresh();
        $this->assertEquals(StaffAttendanceStatus::Present, $record->status);
        $this->assertEquals($admin->id, $record->verified_by);
    }

    public function test_admin_can_view_overview_records_logs_and_export_csv(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00:00', 'Africa/Lagos'));

        StaffAttendanceRecord::query()->create([
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->primaryCampus->id,
            'date' => '2026-11-09',
            'clock_in_at' => Carbon::parse('2026-11-09 07:40:00', 'Africa/Lagos'),
            'clock_in_distance_meters' => 15.2,
            'status' => StaffAttendanceStatus::Present,
        ]);

        $admin = $this->userWithRole(RoleSlug::SuperAdmin);

        // Overview
        $this->actingAs($admin)->getJson('/api/v1/admin/staff-attendance/overview')
            ->assertOk()
            ->assertJsonPath('data.present_count', 1);

        // Records
        $this->actingAs($admin)->getJson('/api/v1/admin/staff-attendance/records')
            ->assertOk()
            ->assertJsonPath('data.0.staff_name', 'John Doe');

        // CSV export
        $export = $this->actingAs($admin)->get('/api/v1/admin/staff-attendance/export');
        $export->assertOk();
        $this->assertStringContainsString('text/csv', $export->headers->get('content-type'));
    }

    public function test_admin_can_manage_staff_campus_assignments(): void
    {
        $admin = $this->userWithRole(RoleSlug::SuperAdmin);

        // Create assignment to Secondary campus
        $response = $this->actingAs($admin)->postJson('/api/v1/admin/staff-attendance/assignments', [
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->secondaryCampus->id,
            'is_primary' => false,
            'start_date' => '2026-11-01',
            'notes' => 'Teaching Biology in senior secondary classes.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.campus_name', 'Secondary School Campus');

        $assignmentId = $response->json('data.id');

        // Update assignment
        $this->actingAs($admin)->putJson("/api/v1/admin/staff-attendance/assignments/{$assignmentId}", [
            'is_primary' => true,
        ])->assertOk()
            ->assertJsonPath('data.is_primary', true);

        // List assignments
        $this->actingAs($admin)->getJson("/api/v1/admin/staff-attendance/assignments?staff_profile_id={$this->staffMember->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Delete assignment
        $this->actingAs($admin)->deleteJson("/api/v1/admin/staff-attendance/assignments/{$assignmentId}")
            ->assertOk();

        $this->assertDatabaseMissing('staff_campus_assignments', ['id' => $assignmentId]);
    }

    public function test_admin_can_record_attendance_manually(): void
    {
        $admin = $this->userWithRole(RoleSlug::SuperAdmin);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/staff-attendance/manual', [
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->primaryCampus->id,
            'date' => '2026-11-08',
            'clock_in_at' => '2026-11-08 07:35:00',
            'clock_out_at' => '2026-11-08 16:05:00',
            'status' => 'approved_exception',
            'reason' => 'Field trip supervision at state academic competition.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'approved_exception')
            ->assertJsonPath('data.verified_by', $admin->name);

        $record = StaffAttendanceRecord::query()->where('staff_profile_id', $this->staffMember->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals(StaffAttendanceStatus::ApprovedException, $record->status);
        $this->assertEquals($admin->id, $record->verified_by);
    }

    public function test_staff_can_view_my_attendance_page(): void
    {
        $response = $this->actingAs($this->staffUser)->get('/staff/my_attendance');
        $response->assertOk();
        $this->assertStringContainsString('My Attendance', $response->getContent());
        $this->assertStringContainsString('GPS Clock Deck', $response->getContent());
    }

    public function test_admin_can_view_portal_attendance_desk(): void
    {
        $admin = $this->userWithRole(RoleSlug::SuperAdmin);
        $response = $this->actingAs($admin)->get('/portal/attendance');
        $response->assertOk();
        $this->assertStringContainsString('Faculty Attendance', $response->getContent());
    }

    public function test_unauthorized_user_cannot_view_portal_attendance_desk(): void
    {
        $student = $this->userWithRole(RoleSlug::Student);
        $response = $this->actingAs($student)->get('/portal/attendance');
        $response->assertRedirect();
    }

    public function test_authorized_cross_campus_clock_out_is_accepted_when_staff_is_assigned_to_destination_campus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Assign staff to Secondary Campus as well
        StaffCampusAssignment::query()->create([
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->secondaryCampus->id,
            'is_primary' => false,
            'start_date' => '2026-11-01',
            'is_active' => true,
        ]);

        // 1. Clock in at Primary Campus
        $clockInResponse = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 12.0,
        ]);
        $clockInResponse->assertCreated();

        // 2. Later in the afternoon, clock out at Secondary Campus
        Carbon::setTestNow(Carbon::parse('2026-11-09 16:15:00', 'Africa/Lagos'));

        $clockOutResponse = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-out', [
            'campus_id' => $this->secondaryCampus->id,
            'latitude' => 5.4872000,
            'longitude' => 7.0368000,
            'accuracy' => 10.0,
        ]);

        $clockOutResponse->assertOk()
            ->assertJsonPath('data.campus_id', $this->primaryCampus->id)
            ->assertJsonPath('data.campus_name', 'Primary School Campus')
            ->assertJsonPath('data.clock_out_campus_id', $this->secondaryCampus->id)
            ->assertJsonPath('data.clock_out_campus_name', 'Secondary School Campus')
            ->assertJsonPath('data.is_cross_campus', true);

        // Verify in database
        $record = StaffAttendanceRecord::query()->where('staff_profile_id', $this->staffMember->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals($this->primaryCampus->id, $record->campus_id);
        $this->assertEquals($this->secondaryCampus->id, $record->clock_out_campus_id);
        $this->assertNotNull($record->clock_in_at);
        $this->assertNotNull($record->clock_out_at);
    }

    public function test_cross_campus_clock_out_is_rejected_when_staff_lacks_assignment_to_destination_campus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Clock in at Primary Campus
        $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 12.0,
        ])->assertCreated();

        // Clock out at Secondary Campus without holding assignment
        Carbon::setTestNow(Carbon::parse('2026-11-09 16:15:00', 'Africa/Lagos'));

        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-out', [
            'campus_id' => $this->secondaryCampus->id,
            'latitude' => 5.4872000,
            'longitude' => 7.0368000,
            'accuracy' => 10.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['campus_id']);
    }

    public function test_cross_campus_clock_out_is_rejected_when_outside_destination_campus_geofence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Assign to Secondary as well
        StaffCampusAssignment::query()->create([
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->secondaryCampus->id,
            'is_primary' => false,
            'start_date' => '2026-11-01',
            'is_active' => true,
        ]);

        // Clock in at Primary
        $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 12.0,
        ])->assertCreated();

        // Attempt clock out at Secondary, but coordinates are 2km away
        Carbon::setTestNow(Carbon::parse('2026-11-09 16:15:00', 'Africa/Lagos'));

        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-out', [
            'campus_id' => $this->secondaryCampus->id,
            'latitude' => 5.5000000,
            'longitude' => 7.0500000,
            'accuracy' => 10.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['location']);
    }

    public function test_cross_campus_clock_out_is_rejected_when_destination_campus_is_inactive(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Assign to Secondary
        StaffCampusAssignment::query()->create([
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->secondaryCampus->id,
            'is_primary' => false,
            'start_date' => '2026-11-01',
            'is_active' => true,
        ]);

        // Clock in at Primary
        $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 12.0,
        ])->assertCreated();

        // Deactivate Secondary campus
        $this->secondaryCampus->update(['is_active' => false]);

        Carbon::setTestNow(Carbon::parse('2026-11-09 16:15:00', 'Africa/Lagos'));

        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-out', [
            'campus_id' => $this->secondaryCampus->id,
            'latitude' => 5.4872000,
            'longitude' => 7.0368000,
            'accuracy' => 10.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['campus_id']);
    }

    public function test_daily_attendance_uniqueness_prevents_duplicate_records_for_same_staff_and_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Clock in first time
        $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 12.0,
        ])->assertCreated();

        // Attempt second clock-in on same day
        $response = $this->actingAs($this->staffUser)->postJson('/api/v1/staff/attendance/clock-in', [
            'campus_id' => $this->primaryCampus->id,
            'latitude' => 5.4851230,
            'longitude' => 7.0354120,
            'accuracy' => 12.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['attendance']);

        // Check DB row count remains exactly 1
        $count = StaffAttendanceRecord::query()
            ->where('staff_profile_id', $this->staffMember->id)
            ->whereDate('date', '2026-11-09')
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_campus_deletion_is_blocked_when_attendance_records_exist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 07:30:00', 'Africa/Lagos'));

        // Create attendance record
        StaffAttendanceRecord::query()->create([
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->primaryCampus->id,
            'date' => '2026-11-09',
            'clock_in_at' => Carbon::now(),
            'status' => StaffAttendanceStatus::Present,
        ]);

        $admin = $this->userWithRole(RoleSlug::SuperAdmin);

        $response = $this->actingAs($admin)->deleteJson("/api/v1/campuses/{$this->primaryCampus->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['campus']);

        $this->assertDatabaseHas('campuses', ['id' => $this->primaryCampus->id]);
    }

    public function test_admin_overview_correctly_scopes_active_staff_to_filtered_campus(): void
    {
        // Create a second staff member assigned ONLY to Secondary Campus
        $secondUser = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Jane Smith', 'email' => 'jane.smith@srs.edu']);
        $secondStaff = $this->staff($secondUser);

        StaffCampusAssignment::query()->create([
            'staff_profile_id' => $secondStaff->id,
            'campus_id' => $this->secondaryCampus->id,
            'is_primary' => true,
            'start_date' => '2026-11-01',
            'is_active' => true,
        ]);

        $admin = $this->userWithRole(RoleSlug::SuperAdmin);

        // Monday
        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00:00', 'Africa/Lagos'));

        // Query with campus_id = Primary Campus
        $response = $this->actingAs($admin)->getJson("/api/v1/admin/staff-attendance/overview?campus_id={$this->primaryCampus->id}&date=2026-11-09");

        $response->assertOk()
            ->assertJsonPath('data.total_active_staff', 1)
            ->assertJsonPath('data.absent_count', 1);

        // Query with campus_id = Secondary Campus
        $responseSec = $this->actingAs($admin)->getJson("/api/v1/admin/staff-attendance/overview?campus_id={$this->secondaryCampus->id}&date=2026-11-09");

        $responseSec->assertOk()
            ->assertJsonPath('data.total_active_staff', 1)
            ->assertJsonPath('data.absent_count', 1);
    }

    public function test_admin_overview_reports_zero_absence_on_non_working_days(): void
    {
        $admin = $this->userWithRole(RoleSlug::SuperAdmin);

        // Sunday date
        $sundayDate = '2026-11-15';
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'Africa/Lagos'));

        $response = $this->actingAs($admin)->getJson("/api/v1/admin/staff-attendance/overview?date={$sundayDate}&campus_id={$this->primaryCampus->id}");

        $response->assertOk()
            ->assertJsonPath('data.is_working_day', false)
            ->assertJsonPath('data.absent_count', 0);
    }

    public function test_polygon_geofence_boundary_is_authoritative_without_circular_radius_fallback(): void
    {
        $geofenceService = app(GeofenceService::class);

        // Create a triangular polygon campus
        $polygonCampus = Campus::query()->create([
            'name' => 'Polygon Testing Campus',
            'code' => 'POLY-CAMPUS',
            'latitude' => 5.4850000,
            'longitude' => 7.0350000,
            'geofence_type' => 'polygon',
            'radius_meters' => 500, // Large centroid radius
            'boundary_coordinates' => [
                ['latitude' => 5.4840000, 'longitude' => 7.0340000],
                ['latitude' => 5.4860000, 'longitude' => 7.0340000],
                ['latitude' => 5.4850000, 'longitude' => 7.0360000],
            ],
            'gps_accuracy_threshold' => 50,
            'is_active' => true,
        ]);

        // Point inside the polygon triangle
        $insideResult = $geofenceService->verify($polygonCampus, 5.4850000, 7.0345000, 15.0);
        $this->assertTrue($insideResult->isValid);
        $this->assertTrue($insideResult->isWithinBoundary);

        // Point outside the triangle, but well within the 500m centroid radius
        // (Previously, circular radius fallback would mistakenly accept this!)
        $outsideResult = $geofenceService->verify($polygonCampus, 5.4870000, 7.0350000, 15.0);
        $this->assertFalse($outsideResult->isValid);
        $this->assertFalse($outsideResult->isWithinBoundary);
        $this->assertStringContainsString('polygon boundary', $outsideResult->rejectionReason);
    }

    public function test_polygon_geofence_safely_rejects_malformed_or_insufficient_boundary_coordinates(): void
    {
        $geofenceService = app(GeofenceService::class);

        $brokenCampus = Campus::query()->create([
            'name' => 'Broken Polygon Campus',
            'code' => 'BROKEN-POLY',
            'latitude' => 5.4850000,
            'longitude' => 7.0350000,
            'geofence_type' => 'polygon',
            'radius_meters' => 100,
            'boundary_coordinates' => [
                ['latitude' => 5.4840000, 'longitude' => 7.0340000], // Only 1 vertex!
            ],
            'gps_accuracy_threshold' => 50,
            'is_active' => true,
        ]);

        $result = $geofenceService->verify($brokenCampus, 5.4850000, 7.0350000, 15.0);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('fewer than 3 boundary coordinates', $result->rejectionReason);
    }

    public function test_attendance_policy_precedence_resolution(): void
    {
        $service = app(StaffAttendanceService::class);

        // Tier 4: Campus default
        $tier4 = $service->resolvePolicy($this->primaryCampus, $this->staffMember);
        $this->assertEquals('campus_default', $tier4->source);
        $this->assertEquals('07:45:00', $tier4->expected_clock_in_time);

        // Tier 3: Campus specific policy (role_slug = null)
        StaffAttendancePolicy::query()->create([
            'campus_id' => $this->primaryCampus->id,
            'role_slug' => null,
            'name' => 'Primary Campus General Policy',
            'expected_clock_in_time' => '07:30:00',
            'grace_period_minutes' => 10,
            'is_active' => true,
        ]);

        $tier3 = $service->resolvePolicy($this->primaryCampus, $this->staffMember);
        $this->assertEquals('campus_policy', $tier3->source);
        $this->assertEquals('07:30:00', $tier3->expected_clock_in_time);
        $this->assertEquals(10, $tier3->grace_period_minutes);

        // Tier 2: Role override (campus_id = null, role = teacher)
        StaffAttendancePolicy::query()->create([
            'campus_id' => null,
            'role_slug' => RoleSlug::Teacher->value,
            'name' => 'All Teachers Policy',
            'expected_clock_in_time' => '07:20:00',
            'grace_period_minutes' => 12,
            'is_active' => true,
        ]);

        $tier2 = $service->resolvePolicy($this->primaryCampus, $this->staffMember);
        $this->assertEquals('role_override', $tier2->source);
        $this->assertEquals('07:20:00', $tier2->expected_clock_in_time);
        $this->assertEquals(12, $tier2->grace_period_minutes);

        // Tier 1: Campus + Role override (campus_id = primary, role = teacher)
        StaffAttendancePolicy::query()->create([
            'campus_id' => $this->primaryCampus->id,
            'role_slug' => RoleSlug::Teacher->value,
            'name' => 'Primary School Teachers Override',
            'expected_clock_in_time' => '07:15:00',
            'grace_period_minutes' => 5,
            'is_active' => true,
        ]);

        $tier1 = $service->resolvePolicy($this->primaryCampus, $this->staffMember);
        $this->assertEquals('campus_role_override', $tier1->source);
        $this->assertEquals('07:15:00', $tier1->expected_clock_in_time);
        $this->assertEquals(5, $tier1->grace_period_minutes);
    }

    public function test_admin_cannot_create_duplicate_active_campus_assignments_for_same_staff(): void
    {
        $admin = $this->userWithRole(RoleSlug::SuperAdmin);

        // $this->staffMember is already actively assigned to $this->primaryCampus in setUp()
        $response = $this->actingAs($admin)->postJson('/api/v1/admin/staff-attendance/assignments', [
            'staff_profile_id' => $this->staffMember->id,
            'campus_id' => $this->primaryCampus->id,
            'is_primary' => false,
            'start_date' => '2026-11-01',
            'is_active' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['campus_id']);
    }
}
