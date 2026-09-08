<?php

namespace App\Models;

use App\Enums\CbtQuestionDifficulty;
use App\Enums\CbtQuestionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'school_class_id',
    'subject_id',
    'topic',
    'difficulty',
    'type',
    'stem',
    'marks',
    'explanation',
    'is_active',
    'created_by',
])]
class CbtQuestion extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'difficulty' => CbtQuestionDifficulty::class,
            'type' => CbtQuestionType::class,
            'marks' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(CbtQuestionOption::class, 'question_id')->orderBy('sort_order');
    }

    public function examQuestions(): HasMany
    {
        return $this->hasMany(CbtExamQuestion::class, 'question_id');
    }
}
