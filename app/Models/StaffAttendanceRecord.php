<?php

namespace App\Models;

use App\Enums\StaffAttendanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'staff_profile_id',
    'campus_id',
    'clock_out_campus_id',
    'date',
    'clock_in_at',
    'clock_out_at',
    'clock_in_latitude',
    'clock_in_longitude',
    'clock_in_accuracy',
    'clock_in_distance_meters',
    'clock_out_latitude',
    'clock_out_longitude',
    'clock_out_accuracy',
    'clock_out_distance_meters',
    'status',
    'clock_in_device',
    'clock_out_device',
    'clock_in_ip',
    'clock_out_ip',
    'is_flagged',
    'flag_reason',
    'notes',
    'verified_by',
])]
class StaffAttendanceRecord extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'clock_in_latitude' => 'float',
            'clock_in_longitude' => 'float',
            'clock_in_accuracy' => 'float',
            'clock_in_distance_meters' => 'float',
            'clock_out_latitude' => 'float',
            'clock_out_longitude' => 'float',
            'clock_out_accuracy' => 'float',
            'clock_out_distance_meters' => 'float',
            'status' => StaffAttendanceStatus::class,
            'is_flagged' => 'boolean',
        ];
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function clockOutCampus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'clock_out_campus_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(StaffAttendanceAdjustment::class);
    }

    public function durationMinutes(): ?int
    {
        if (! $this->clock_in_at || ! $this->clock_out_at) {
            return null;
        }

        return (int) $this->clock_in_at->diffInMinutes($this->clock_out_at);
    }
}

