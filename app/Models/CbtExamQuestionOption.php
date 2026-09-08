<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'exam_question_id',
    'source_option_id',
    'label',
    'body',
    'is_correct',
    'sort_order',
])]
class CbtExamQuestionOption extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function examQuestion(): BelongsTo
    {
        return $this->belongsTo(CbtExamQuestion::class, 'exam_question_id');
    }

    public function sourceOption(): BelongsTo
    {
        return $this->belongsTo(CbtQuestionOption::class, 'source_option_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CbtAnswer::class, 'selected_exam_option_id');
    }
}
