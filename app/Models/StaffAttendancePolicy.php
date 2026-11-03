<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_id',
    'role_slug',
    'name',
    'expected_clock_in_time',
    'expected_clock_out_time',
    'grace_period_minutes',
    'operating_hours_start',
    'operating_hours_end',
    'working_days',
    'is_active',
])]
class StaffAttendancePolicy extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'grace_period_minutes' => 'integer',
            'working_days' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}

