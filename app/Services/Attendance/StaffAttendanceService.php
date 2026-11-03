<?php

namespace App\Services\Attendance;

use App\Enums\StaffAttendanceStatus;
use App\Enums\StaffStatus;
use App\Enums\UserStatus;
use App\Models\Campus;
use App\Models\StaffAttendanceAdjustment;
use App\Models\StaffAttendancePolicy;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffAttendanceVerificationLog;
use App\Models\StaffCampusAssignment;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffAttendanceService
{
    public function __construct(
        protected GeofenceService $geofenceService
    ) {}

    /**
     * Resolve attendance policy for a campus and staff member with strict precedence:
     * 1. Campus + Role override (campus_id matches, role matches)
     * 2. Role override (campus_id is null, role matches)
     * 3. Campus specific policy (campus_id matches, role_slug is null)
     * 4. Campus default columns
     */
    public function resolvePolicy(Campus $campus, ?StaffProfile $staff = null): object
    {
        $roleSlugs = $staff?->user?->roleSlugs() ?? collect();

        $policy = null;
        $source = 'campus_default';

        if ($roleSlugs->isNotEmpty()) {
            $policy = StaffAttendancePolicy::query()
                ->where('campus_id', $campus->id)
                ->whereIn('role_slug', $roleSlugs)
                ->where('is_active', true)
                ->first();

            if ($policy) {
                $source = 'campus_role_override';
            }
        }

        if (! $policy && $roleSlugs->isNotEmpty()) {
            $policy = StaffAttendancePolicy::query()
                ->whereNull('campus_id')
                ->whereIn('role_slug', $roleSlugs)
                ->where('is_active', true)
                ->first();

            if ($policy) {
                $source = 'role_override';
            }
        }

        if (! $policy) {
            $policy = StaffAttendancePolicy::query()
                ->where('campus_id', $campus->id)
                ->whereNull('role_slug')
                ->where('is_active', true)
                ->first();

            if ($policy) {
                $source = 'campus_policy';
            }
        }

        $defaultWorkingDays = $campus->working_days ?: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

        return (object) [
            'source' => $source,
            'policy_id' => $policy?->id,
            'policy_name' => $policy?->name ?? 'Default Campus Policy',
            'expected_clock_in_time' => $policy?->expected_clock_in_time ?: ($campus->expected_clock_in_time ?: '07:45:00'),
            'expected_clock_out_time' => $policy?->expected_clock_out_time ?: ($campus->expected_clock_out_time ?: '16:00:00'),
            'grace_period_minutes' => $policy?->grace_period_minutes ?? ($campus->grace_period_minutes ?? 15),
            'operating_hours_start' => $policy?->operating_hours_start ?: ($campus->operating_hours_start ?: '06:30:00'),
            'operating_hours_end' => $policy?->operating_hours_end ?: ($campus->operating_hours_end ?: '18:30:00'),
            'working_days' => $policy?->working_days ?: $defaultWorkingDays,
        ];
    }

    /**
     * Clock in staff at a specific campus with GPS verification.
     */
    public function clockIn(
        StaffProfile $staff,
        int $campusId,
        float $latitude,
        float $longitude,
        float $accuracy,
        ?string $device = null,
        ?string $ip = null
    ): StaffAttendanceRecord {
        $now = now('Africa/Lagos');
        $today = $now->toDateString();

        // 1. Verify active campus assignment on attendance date
        $isAssigned = StaffCampusAssignment::query()
            ->where('staff_profile_id', $staff->id)
            ->where('campus_id', $campusId)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', $today);
            })
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $today);
            })
            ->exists();

        if (! $isAssigned) {
            throw ValidationException::withMessages([
                'campus_id' => 'You are not actively assigned to this campus.',
            ]);
        }

        // 2. Verify campus validity
        $campus = Campus::query()->find($campusId);
        if (! $campus || ! $campus->is_active) {
            throw ValidationException::withMessages([
                'campus_id' => 'Selected campus is not active.',
            ]);
        }

        if (! $campus->hasValidCoordinates()) {
            throw ValidationException::withMessages([
                'campus_id' => 'Selected campus does not have GPS geofence coordinates configured.',
            ]);
        }

        // 3. Resolve policy precedence
        $policy = $this->resolvePolicy($campus, $staff);

        // 4. Verify working day
        $workingDays = $policy->working_days;
        $currentDay = strtolower($now->format('l'));
        if (! in_array($currentDay, array_map('strtolower', $workingDays), true)) {
            throw ValidationException::withMessages([
                'schedule' => "Today ({$now->format('l')}) is not a designated working day for this campus.",
            ]);
        }

        // 5. Verify operating hours
        $startTime = $policy->operating_hours_start;
        $endTime = $policy->operating_hours_end;
        $currentTime = $now->format('H:i:s');
        if ($currentTime < $startTime || $currentTime > $endTime) {
            throw ValidationException::withMessages([
                'schedule' => "Clock-in is only permitted during campus operating hours ({$startTime} - {$endTime}).",
            ]);
        }

        // 6. Geofence verification
        $geofenceResult = $this->geofenceService->verify($campus, $latitude, $longitude, $accuracy, $staff);

        // 7. Audit log the attempt (persisted regardless of verification outcome)
        StaffAttendanceVerificationLog::query()->create([
            'staff_profile_id' => $staff->id,
            'campus_id' => $campus->id,
            'action' => 'clock_in',
            'attempted_at' => $now,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'gps_accuracy' => $accuracy,
            'distance_meters' => $geofenceResult->distanceMeters,
            'is_within_boundary' => $geofenceResult->isWithinBoundary,
            'accuracy_acceptable' => $geofenceResult->isAccuracyAcceptable,
            'result' => $geofenceResult->isValid ? 'accepted' : 'rejected',
            'rejection_reason' => $geofenceResult->rejectionReason,
            'is_suspicious' => $geofenceResult->isSuspicious,
            'ip_address' => $ip,
            'user_agent' => $device,
            'metadata' => [
                'suspicious_reason' => $geofenceResult->suspiciousReason,
                'policy_source' => $policy->source,
                'policy_name' => $policy->policy_name,
            ],
        ]);

        if (! $geofenceResult->isValid) {
            throw ValidationException::withMessages([
                'location' => $geofenceResult->rejectionReason,
            ]);
        }

        // 8. Determine punctuality status
        $expectedIn = $policy->expected_clock_in_time;
        $graceMinutes = $policy->grace_period_minutes;
        $lateThreshold = Carbon::parse($today.' '.$expectedIn, 'Africa/Lagos')->addMinutes($graceMinutes);

        $status = $now->isAfter($lateThreshold)
            ? StaffAttendanceStatus::Late
            : StaffAttendanceStatus::Present;

        // 9. Atomic record creation / update under lock with concurrency & uniqueness guard
        return DB::transaction(function () use ($staff, $campus, $today, $now, $latitude, $longitude, $accuracy, $geofenceResult, $status, $device, $ip) {
            $existingRecord = StaffAttendanceRecord::query()
                ->where('staff_profile_id', $staff->id)
                ->whereDate('date', $today)
                ->lockForUpdate()
                ->first();

            if ($existingRecord && $existingRecord->clock_in_at) {
                throw ValidationException::withMessages([
                    'attendance' => 'You have already clocked in for today at '.$existingRecord->clock_in_at->setTimezone('Africa/Lagos')->format('h:i A').'.',
                ]);
            }

            try {
                if ($existingRecord) {
                    $existingRecord->update([
                        'campus_id' => $campus->id,
                        'clock_in_at' => $now,
                        'clock_in_latitude' => $latitude,
                        'clock_in_longitude' => $longitude,
                        'clock_in_accuracy' => $accuracy,
                        'clock_in_distance_meters' => $geofenceResult->distanceMeters,
                        'status' => $status,
                        'clock_in_device' => $device,
                        'clock_in_ip' => $ip,
                        'is_flagged' => $geofenceResult->isSuspicious,
                        'flag_reason' => $geofenceResult->suspiciousReason,
                    ]);

                    return $existingRecord->fresh(['campus', 'clockOutCampus', 'staffProfile.user']);
                }

                return StaffAttendanceRecord::query()->create([
                    'staff_profile_id' => $staff->id,
                    'campus_id' => $campus->id,
                    'date' => $today,
                    'clock_in_at' => $now,
                    'clock_in_latitude' => $latitude,
                    'clock_in_longitude' => $longitude,
                    'clock_in_accuracy' => $accuracy,
                    'clock_in_distance_meters' => $geofenceResult->distanceMeters,
                    'status' => $status,
                    'clock_in_device' => $device,
                    'clock_in_ip' => $ip,
                    'is_flagged' => $geofenceResult->isSuspicious,
                    'flag_reason' => $geofenceResult->suspiciousReason,
                ])->load(['campus', 'clockOutCampus', 'staffProfile.user']);
            } catch (UniqueConstraintViolationException $e) {
                $raceRecord = StaffAttendanceRecord::query()
                    ->where('staff_profile_id', $staff->id)
                    ->whereDate('date', $today)
                    ->first();

                throw ValidationException::withMessages([
                    'attendance' => 'You have already clocked in for today'.($raceRecord?->clock_in_at ? ' at '.$raceRecord->clock_in_at->setTimezone('Africa/Lagos')->format('h:i A') : '').'.',
                ]);
            }
        });
    }

    /**
     * Clock out staff with GPS verification.
     * Supports authorized cross-campus clock-out if staff is actively assigned to the destination campus.
     */
    public function clockOut(
        StaffProfile $staff,
        int $campusId,
        float $latitude,
        float $longitude,
        float $accuracy,
        ?string $device = null,
        ?string $ip = null
    ): StaffAttendanceRecord {
        $now = now('Africa/Lagos');
        $today = $now->toDateString();

        // 1. Validate destination campus
        $campus = Campus::query()->find($campusId);
        if (! $campus || ! $campus->is_active) {
            throw ValidationException::withMessages([
                'campus_id' => 'Selected clock-out campus is not active.',
            ]);
        }

        if (! $campus->hasValidCoordinates()) {
            throw ValidationException::withMessages([
                'campus_id' => 'Selected campus does not have GPS geofence coordinates configured.',
            ]);
        }

        // 2. Verify active assignment to destination campus on attendance date
        $isAssigned = StaffCampusAssignment::query()
            ->where('staff_profile_id', $staff->id)
            ->where('campus_id', $campusId)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', $today);
            })
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $today);
            })
            ->exists();

        if (! $isAssigned) {
            throw ValidationException::withMessages([
                'campus_id' => 'You are not actively assigned to this campus for clock-out.',
            ]);
        }

        // 3. Geofence verification against destination campus
        $geofenceResult = $this->geofenceService->verify($campus, $latitude, $longitude, $accuracy, $staff);

        // 4. Audit log attempt
        StaffAttendanceVerificationLog::query()->create([
            'staff_profile_id' => $staff->id,
            'campus_id' => $campus->id,
            'action' => 'clock_out',
            'attempted_at' => $now,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'gps_accuracy' => $accuracy,
            'distance_meters' => $geofenceResult->distanceMeters,
            'is_within_boundary' => $geofenceResult->isWithinBoundary,
            'accuracy_acceptable' => $geofenceResult->isAccuracyAcceptable,
            'result' => $geofenceResult->isValid ? 'accepted' : 'rejected',
            'rejection_reason' => $geofenceResult->rejectionReason,
            'is_suspicious' => $geofenceResult->isSuspicious,
            'ip_address' => $ip,
            'user_agent' => $device,
            'metadata' => [
                'suspicious_reason' => $geofenceResult->suspiciousReason,
            ],
        ]);

        if (! $geofenceResult->isValid) {
            throw ValidationException::withMessages([
                'location' => $geofenceResult->rejectionReason,
            ]);
        }

        // 5. Transactional clock-out with row lock
        return DB::transaction(function () use ($staff, $campus, $today, $now, $latitude, $longitude, $accuracy, $geofenceResult, $device, $ip) {
            $record = StaffAttendanceRecord::query()
                ->where('staff_profile_id', $staff->id)
                ->whereDate('date', $today)
                ->lockForUpdate()
                ->first();

            if (! $record || ! $record->clock_in_at) {
                throw ValidationException::withMessages([
                    'attendance' => 'No active clock-in found for today. You must clock in first before clocking out.',
                ]);
            }

            if ($record->clock_out_at) {
                throw ValidationException::withMessages([
                    'attendance' => 'You have already clocked out for today at '.$record->clock_out_at->setTimezone('Africa/Lagos')->format('h:i A').'.',
                ]);
            }

            // Early departure check based on destination campus policy
            $policy = $this->resolvePolicy($campus, $staff);
            $expectedOut = $policy->expected_clock_out_time;
            $departureThreshold = Carbon::parse($today.' '.$expectedOut, 'Africa/Lagos');

            $status = $record->status;
            if ($now->isBefore($departureThreshold) && $status === StaffAttendanceStatus::Present) {
                $status = StaffAttendanceStatus::EarlyDeparture;
            }

            // Update record: Preserve original campus_id (clock-in campus) and set clock_out_campus_id
            $record->update([
                'clock_out_campus_id' => $campus->id,
                'clock_out_at' => $now,
                'clock_out_latitude' => $latitude,
                'clock_out_longitude' => $longitude,
                'clock_out_accuracy' => $accuracy,
                'clock_out_distance_meters' => $geofenceResult->distanceMeters,
                'status' => $status,
                'clock_out_device' => $device,
                'clock_out_ip' => $ip,
                'is_flagged' => $record->is_flagged || $geofenceResult->isSuspicious,
                'flag_reason' => $record->flag_reason ?: $geofenceResult->suspiciousReason,
            ]);

            return $record->fresh(['campus', 'clockOutCampus', 'staffProfile.user']);
        });
    }

    /**
     * Get real-time overview and statistics for an individual staff member.
     */
    public function getStaffOverview(StaffProfile $staff): array
    {
        $now = now('Africa/Lagos');
        $today = $now->toDateString();
        $startOfMonth = $now->copy()->startOfMonth()->toDateString();
        $endOfMonth = $now->copy()->endOfMonth()->toDateString();

        // Today's record
        $todayRecord = StaffAttendanceRecord::query()
            ->with(['campus', 'clockOutCampus'])
            ->where('staff_profile_id', $staff->id)
            ->whereDate('date', $today)
            ->first();

        // Active campus assignments
        $assignedCampuses = $staff->activeCampuses()
            ->get()
            ->map(fn (Campus $campus) => [
                'id' => $campus->id,
                'name' => $campus->name,
                'code' => $campus->code,
                'address' => $campus->address,
                'latitude' => $campus->latitude,
                'longitude' => $campus->longitude,
                'radius_meters' => $campus->radius_meters,
                'gps_accuracy_threshold' => $campus->gps_accuracy_threshold,
                'is_primary' => (bool) ($campus->pivot->is_primary ?? false),
                'expected_clock_in_time' => $campus->expected_clock_in_time,
                'expected_clock_out_time' => $campus->expected_clock_out_time,
            ]);

        // Monthly counts
        $monthRecords = StaffAttendanceRecord::query()
            ->where('staff_profile_id', $staff->id)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->get();

        $presentCount = $monthRecords->whereIn('status', [StaffAttendanceStatus::Present, StaffAttendanceStatus::EarlyDeparture])->count();
        $lateCount = $monthRecords->where('status', StaffAttendanceStatus::Late)->count();
        $missingClockOutCount = $monthRecords->filter(fn ($r) => $r->clock_in_at && ! $r->clock_out_at && $r->date->isBefore($now->copy()->startOfDay()))->count();

        $totalMinutes = $monthRecords->reduce(function (int $carry, StaffAttendanceRecord $record) {
            return $carry + ($record->durationMinutes() ?? 0);
        }, 0);

        return [
            'today' => [
                'date' => $today,
                'has_clocked_in' => (bool) ($todayRecord && $todayRecord->clock_in_at),
                'has_clocked_out' => (bool) ($todayRecord && $todayRecord->clock_out_at),
                'clock_in_time' => $todayRecord?->clock_in_at?->setTimezone('Africa/Lagos')->format('h:i A'),
                'clock_out_time' => $todayRecord?->clock_out_at?->setTimezone('Africa/Lagos')->format('h:i A'),
                'status' => $todayRecord?->status?->value,
                'status_label' => $todayRecord?->status?->label(),
                'campus_name' => $todayRecord?->campus?->name,
                'campus_id' => $todayRecord?->campus_id,
                'clock_out_campus_name' => $todayRecord?->clockOutCampus?->name,
                'clock_out_campus_id' => $todayRecord?->clock_out_campus_id,
                'is_cross_campus' => $todayRecord?->clock_out_campus_id !== null && $todayRecord?->clock_out_campus_id !== $todayRecord?->campus_id,
                'record_id' => $todayRecord?->id,
            ],
            'campuses' => $assignedCampuses,
            'month_stats' => [
                'month' => $now->format('F Y'),
                'present_days' => $presentCount,
                'late_days' => $lateCount,
                'missing_clock_outs' => $missingClockOutCount,
                'total_hours' => round($totalMinutes / 60, 1),
            ],
        ];
    }

    /**
     * Get paginated attendance history for a staff member.
     */
    public function getStaffHistory(StaffProfile $staff, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = StaffAttendanceRecord::query()
            ->with(['campus', 'clockOutCampus', 'adjustments'])
            ->where('staff_profile_id', $staff->id)
            ->latest('date');

        if (! empty($filters['month']) && ! empty($filters['year'])) {
            $query->whereYear('date', (int) $filters['year'])
                ->whereMonth('date', (int) $filters['month']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Submit an attendance correction request.
     */
    public function requestCorrection(
        StaffProfile $staff,
        int $recordId,
        array $requestedValues,
        string $reason,
        User $user
    ): StaffAttendanceAdjustment {
        $record = StaffAttendanceRecord::query()
            ->where('staff_profile_id', $staff->id)
            ->findOrFail($recordId);

        $hasPending = StaffAttendanceAdjustment::query()
            ->where('staff_attendance_record_id', $record->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages([
                'adjustment' => 'A pending correction request already exists for this attendance record.',
            ]);
        }

        return StaffAttendanceAdjustment::query()->create([
            'staff_attendance_record_id' => $record->id,
            'requested_by' => $user->id,
            'original_values' => [
                'clock_in_at' => $record->clock_in_at?->toIso8601String(),
                'clock_out_at' => $record->clock_out_at?->toIso8601String(),
                'status' => $record->status->value,
            ],
            'requested_values' => $requestedValues,
            'reason' => $reason,
            'status' => 'pending',
        ]);
    }

    /**
     * Get administrative overview metrics for today.
     * Accurately scopes active staff and absence metrics by campus and working days.
     */
    public function getAdminOverview(array $filters = []): array
    {
        $now = now('Africa/Lagos');
        $today = $filters['date'] ?? $now->toDateString();
        $todayCarbon = Carbon::parse($today, 'Africa/Lagos');
        $dayOfWeek = strtolower($todayCarbon->format('l'));

        $isWorkingDay = true;
        if (! empty($filters['campus_id'])) {
            $campus = Campus::query()->find($filters['campus_id']);
            $policy = $campus ? $this->resolvePolicy($campus) : null;
            $workingDays = $policy?->working_days ?: ($campus?->working_days ?: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);
            $isWorkingDay = in_array($dayOfWeek, array_map('strtolower', $workingDays), true);

            // Active staff specifically assigned to this campus on the requested date
            $activeStaffQuery = StaffProfile::query()
                ->where('status', StaffStatus::Active)
                ->whereHas('user', fn ($q) => $q->where('status', UserStatus::Active))
                ->whereHas('campusAssignments', function (Builder $query) use ($filters, $today) {
                    $query->where('campus_id', $filters['campus_id'])
                        ->where('is_active', true)
                        ->where(function (Builder $sub) use ($today) {
                            $sub->whereNull('start_date')
                                ->orWhere('start_date', '<=', $today);
                        })
                        ->where(function (Builder $sub) use ($today) {
                            $sub->whereNull('end_date')
                                ->orWhere('end_date', '>=', $today);
                        });
                });
        } else {
            // General school default working days (Monday to Friday)
            $isWorkingDay = ! in_array($dayOfWeek, ['saturday', 'sunday'], true);

            $activeStaffQuery = StaffProfile::query()
                ->where('status', StaffStatus::Active)
                ->whereHas('user', fn ($q) => $q->where('status', UserStatus::Active));
        }

        $activeStaffCount = $activeStaffQuery->count();

        $todayRecordsQuery = StaffAttendanceRecord::query()
            ->with(['staffProfile.user', 'campus', 'clockOutCampus'])
            ->whereDate('date', $today);

        if (! empty($filters['campus_id'])) {
            $todayRecordsQuery->where(function (Builder $q) use ($filters) {
                $q->where('campus_id', $filters['campus_id'])
                    ->orWhere('clock_out_campus_id', $filters['campus_id']);
            });
        }

        $records = $todayRecordsQuery->get();

        $presentCount = $records->whereIn('status', [StaffAttendanceStatus::Present, StaffAttendanceStatus::EarlyDeparture])->count();
        $lateCount = $records->where('status', StaffAttendanceStatus::Late)->count();
        $flaggedCount = $records->where('is_flagged', true)->count();
        $missingClockOutCount = $records->filter(fn ($r) => $r->clock_in_at && ! $r->clock_out_at)->count();

        // Calculate estimated absent: on non-working days, absence count is 0
        if (! $isWorkingDay) {
            $absentCount = 0;
        } else {
            $totalRecorded = $records->unique('staff_profile_id')->count();
            $absentCount = max(0, $activeStaffCount - $totalRecorded);
        }

        // Verification logs summary
        $logsQuery = StaffAttendanceVerificationLog::query()
            ->whereDate('attempted_at', $today);
        if (! empty($filters['campus_id'])) {
            $logsQuery->where('campus_id', $filters['campus_id']);
        }

        $rejectedAttempts = (clone $logsQuery)->where('result', 'rejected')->count();
        $suspiciousAttempts = (clone $logsQuery)->where('is_suspicious', true)->count();

        // Pending corrections
        $pendingCorrections = StaffAttendanceAdjustment::query()->where('status', 'pending')->count();

        return [
            'date' => $today,
            'is_working_day' => $isWorkingDay,
            'total_active_staff' => $activeStaffCount,
            'present_count' => $presentCount,
            'late_count' => $lateCount,
            'absent_count' => $absentCount,
            'missing_clock_out_count' => $missingClockOutCount,
            'flagged_count' => $flaggedCount,
            'rejected_attempts' => $rejectedAttempts,
            'suspicious_attempts' => $suspiciousAttempts,
            'pending_corrections' => $pendingCorrections,
        ];
    }

    /**
     * Get administrative live roll and attendance records ledger.
     */
    public function getAdminAttendanceList(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = StaffAttendanceRecord::query()
            ->with(['staffProfile.user', 'staffProfile.department', 'campus', 'clockOutCampus', 'verifier'])
            ->latest('date')
            ->latest('clock_in_at');

        if (! empty($filters['date'])) {
            $query->whereDate('date', $filters['date']);
        } elseif (! empty($filters['start_date']) && ! empty($filters['end_date'])) {
            $query->whereBetween('date', [$filters['start_date'], $filters['end_date']]);
        }

        if (! empty($filters['campus_id'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('campus_id', $filters['campus_id'])
                    ->orWhere('clock_out_campus_id', $filters['campus_id']);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['is_flagged']) && $filters['is_flagged'] !== '') {
            $query->where('is_flagged', (bool) $filters['is_flagged']);
        }

        if (! empty($filters['department_id'])) {
            $query->whereHas('staffProfile', fn ($q) => $q->where('department_id', $filters['department_id']));
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->whereHas('staffProfile', function (Builder $q) use ($search) {
                $q->where('staff_number', 'like', $search)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $search)->orWhere('email', 'like', $search));
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Get administrative verification logs.
     */
    public function getAdminVerificationLogs(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = StaffAttendanceVerificationLog::query()
            ->with(['staffProfile.user', 'campus'])
            ->latest('attempted_at');

        if (! empty($filters['date'])) {
            $query->whereDate('attempted_at', $filters['date']);
        }

        if (! empty($filters['campus_id'])) {
            $query->where('campus_id', $filters['campus_id']);
        }

        if (! empty($filters['result'])) {
            $query->where('result', $filters['result']);
        }

        if (isset($filters['is_suspicious']) && $filters['is_suspicious'] !== '') {
            $query->where('is_suspicious', (bool) $filters['is_suspicious']);
        }

        if (! empty($filters['staff_profile_id'])) {
            $query->where('staff_profile_id', $filters['staff_profile_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Review staff attendance correction request.
     */
    public function reviewAdjustment(
        int $adjustmentId,
        bool $approve,
        ?string $reviewNotes,
        User $reviewer
    ): StaffAttendanceAdjustment {
        return DB::transaction(function () use ($adjustmentId, $approve, $reviewNotes, $reviewer) {
            $adjustment = StaffAttendanceAdjustment::query()->with('attendanceRecord')->findOrFail($adjustmentId);

            if ($adjustment->status !== 'pending') {
                throw ValidationException::withMessages([
                    'adjustment' => 'This correction request has already been reviewed.',
                ]);
            }

            $now = now('Africa/Lagos');

            if ($approve) {
                $record = $adjustment->attendanceRecord;
                $requested = $adjustment->requested_values;

                $updates = [];
                if (! empty($requested['clock_in_at'])) {
                    $updates['clock_in_at'] = Carbon::parse($requested['clock_in_at'], 'Africa/Lagos');
                }
                if (! empty($requested['clock_out_at'])) {
                    $updates['clock_out_at'] = Carbon::parse($requested['clock_out_at'], 'Africa/Lagos');
                }
                if (! empty($requested['status'])) {
                    $updates['status'] = StaffAttendanceStatus::from($requested['status']);
                }
                $updates['verified_by'] = $reviewer->id;
                $updates['notes'] = trim(($record->notes ?? '')."\n[Adjusted via approval by {$reviewer->name} on {$now->toDateTimeString()}]");

                $record->update($updates);

                $adjustment->update([
                    'status' => 'approved',
                    'reviewed_by' => $reviewer->id,
                    'review_notes' => $reviewNotes,
                    'reviewed_at' => $now,
                ]);
            } else {
                $adjustment->update([
                    'status' => 'rejected',
                    'reviewed_by' => $reviewer->id,
                    'review_notes' => $reviewNotes,
                    'reviewed_at' => $now,
                ]);
            }

            return $adjustment->fresh(['attendanceRecord', 'requester', 'reviewer']);
        });
    }

    /**
     * Administrative manual attendance entry or override.
     */
    public function manualRecord(
        int $staffProfileId,
        int $campusId,
        string $date,
        ?string $clockInAt,
        ?string $clockOutAt,
        StaffAttendanceStatus $status,
        string $reason,
        User $admin
    ): StaffAttendanceRecord {
        $staff = StaffProfile::query()->findOrFail($staffProfileId);
        $campus = Campus::query()->findOrFail($campusId);

        $record = StaffAttendanceRecord::query()
            ->where('staff_profile_id', $staff->id)
            ->whereDate('date', $date)
            ->first();

        $data = [
            'staff_profile_id' => $staff->id,
            'campus_id' => $campus->id,
            'date' => $date,
            'clock_in_at' => $clockInAt ? Carbon::parse($clockInAt, 'Africa/Lagos') : null,
            'clock_out_at' => $clockOutAt ? Carbon::parse($clockOutAt, 'Africa/Lagos') : null,
            'status' => $status,
            'verified_by' => $admin->id,
            'notes' => "[Manual entry by {$admin->name} on ".now()->toDateTimeString()."]: {$reason}",
        ];

        if ($record) {
            $record->update($data);

            return $record->fresh(['campus', 'clockOutCampus', 'staffProfile.user', 'verifier']);
        }

        return StaffAttendanceRecord::query()->create($data)->load(['campus', 'clockOutCampus', 'staffProfile.user', 'verifier']);
    }
}
