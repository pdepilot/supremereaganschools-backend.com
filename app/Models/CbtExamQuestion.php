<?php

namespace App\Models;

use App\Enums\CbtQuestionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Exam-scoped question configuration. After publish (`is_frozen`), stem/marks/options
 * are immutable even if the linked bank question is edited later.
 */
#[Fillable([
    'exam_id',
    'question_id',
    'sort_order',
    'marks',
    'type',
    'stem',
    'explanation',
    'is_frozen',
    'frozen_at',
])]
class CbtExamQuestion extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'marks' => 'decimal:2',
            'type' => CbtQuestionType::class,
            'is_frozen' => 'boolean',
            'frozen_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(CbtExam::class, 'exam_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class, 'question_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(CbtExamQuestionOption::class, 'exam_question_id')->orderBy('sort_order');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CbtAnswer::class, 'exam_question_id');
    }
}
