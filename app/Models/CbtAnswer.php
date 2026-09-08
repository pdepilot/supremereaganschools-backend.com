<?php

namespace App\Models;

use App\Enums\CbtSyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Authoritative answer link is `exam_question_id` (exam-scoped configuration).
 * `question_id` is optional denormalized provenance for reporting queries only.
 */
#[Fillable([
    'attempt_id',
    'exam_question_id',
    'question_id',
    'selected_exam_option_id',
    'answered_at',
    'client_answered_at',
    'sync_status',
])]
class CbtAnswer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'client_answered_at' => 'datetime',
            'sync_status' => CbtSyncStatus::class,
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CbtAttempt::class, 'attempt_id');
    }

    public function examQuestion(): BelongsTo
    {
        return $this->belongsTo(CbtExamQuestion::class, 'exam_question_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class, 'question_id');
    }

    public function selectedExamOption(): BelongsTo
    {
        return $this->belongsTo(CbtExamQuestionOption::class, 'selected_exam_option_id');
    }
}
