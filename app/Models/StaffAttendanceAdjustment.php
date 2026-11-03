<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'staff_attendance_record_id',
    'requested_by',
    'reviewed_by',
    'original_values',
    'requested_values',
    'reason',
    'status',
    'review_notes',
    'reviewed_at',
])]
class StaffAttendanceAdjustment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'original_values' => 'array',
            'requested_values' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(StaffAttendanceRecord::class, 'staff_attendance_record_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

