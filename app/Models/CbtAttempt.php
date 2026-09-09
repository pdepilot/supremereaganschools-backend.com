<?php

namespace App\Models;

use App\Enums\CbtAttemptMode;
use App\Enums\CbtAttemptStatus;
use App\Enums\CbtSyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'uuid',
    'exam_id',
    'user_id',
    'student_profile_id',
    'enrollment_id',
    'device_id',
    'status',
    'mode',
    'sync_status',
    'started_at',
    'ends_at',
    'submitted_at',
    'submission_reason',
    'client_submitted_at',
    'ip_address',
])]
class CbtAttempt extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => CbtAttemptStatus::class,
            'mode' => CbtAttemptMode::class,
            'sync_status' => CbtSyncStatus::class,
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'submitted_at' => 'datetime',
            'client_submitted_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(CbtExam::class, 'exam_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CbtAnswer::class, 'attempt_id');
    }

    public function result(): HasOne
    {
        return $this->hasOne(CbtResult::class, 'attempt_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(CbtSyncLog::class, 'attempt_id');
    }

    public function integrityEvents(): HasMany
    {
        return $this->hasMany(CbtExamIntegrityEvent::class, 'attempt_id');
    }
}
