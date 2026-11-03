<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'code',
    'address',
    'latitude',
    'longitude',
    'geofence_type',
    'radius_meters',
    'boundary_coordinates',
    'gps_accuracy_threshold',
    'operating_hours_start',
    'operating_hours_end',
    'expected_clock_in_time',
    'expected_clock_out_time',
    'grace_period_minutes',
    'working_days',
    'is_active',
])]
class Campus extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'integer',
            'boundary_coordinates' => 'array',
            'gps_accuracy_threshold' => 'integer',
            'grace_period_minutes' => 'integer',
            'working_days' => 'array',
        ];
    }

    public function classSectionOfferings(): HasMany
    {
        return $this->hasMany(ClassSectionOffering::class);
    }

    public function staffCampusAssignments(): HasMany
    {
        return $this->hasMany(StaffCampusAssignment::class);
    }

    public function staffProfiles(): BelongsToMany
    {
        return $this->belongsToMany(StaffProfile::class, 'staff_campus_assignments')
            ->withPivot(['id', 'is_primary', 'start_date', 'end_date', 'is_active', 'assigned_by', 'notes'])
            ->withTimestamps();
    }

    public function staffAttendanceRecords(): HasMany
    {
        return $this->hasMany(StaffAttendanceRecord::class);
    }

    public function clockOutStaffAttendanceRecords(): HasMany
    {
        return $this->hasMany(StaffAttendanceRecord::class, 'clock_out_campus_id');
    }

    public function staffAttendanceVerificationLogs(): HasMany
    {
        return $this->hasMany(StaffAttendanceVerificationLog::class);
    }

    public function attendancePolicies(): HasMany
    {
        return $this->hasMany(StaffAttendancePolicy::class);
    }

    public function hasValidCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
