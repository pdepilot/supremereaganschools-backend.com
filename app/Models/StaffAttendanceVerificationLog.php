<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'staff_profile_id',
    'campus_id',
    'action',
    'attempted_at',
    'latitude',
    'longitude',
    'gps_accuracy',
    'distance_meters',
    'is_within_boundary',
    'accuracy_acceptable',
    'result',
    'rejection_reason',
    'is_suspicious',
    'ip_address',
    'user_agent',
    'metadata',
])]
class StaffAttendanceVerificationLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'gps_accuracy' => 'float',
            'distance_meters' => 'float',
            'is_within_boundary' => 'boolean',
            'accuracy_acceptable' => 'boolean',
            'is_suspicious' => 'boolean',
            'metadata' => 'array',
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
}

