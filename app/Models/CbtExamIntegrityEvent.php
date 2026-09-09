<?php

namespace App\Models;

use App\Enums\CbtIntegrityEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'correlation_id',
    'attempt_id',
    'student_profile_id',
    'event_type',
    'occurred_at',
    'client_occurred_at',
    'metadata',
])]
class CbtExamIntegrityEvent extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'event_type' => CbtIntegrityEventType::class,
            'occurred_at' => 'datetime',
            'client_occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CbtAttempt::class, 'attempt_id');
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }
}
