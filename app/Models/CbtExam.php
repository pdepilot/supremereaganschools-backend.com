<?php

namespace App\Models;

use App\Enums\CbtExamStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title',
    'instructions',
    'subject_id',
    'class_section_offering_id',
    'academic_session_id',
    'term_id',
    'starts_at',
    'ends_at',
    'duration_minutes',
    'question_count',
    'pass_mark',
    'max_score',
    'randomize_questions',
    'randomize_options',
    'max_attempts',
    'status',
    'published_at',
    'is_active',
    'write_to_assessment_score',
    'created_by',
])]
class CbtExam extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'duration_minutes' => 'integer',
            'question_count' => 'integer',
            'pass_mark' => 'decimal:2',
            'max_score' => 'decimal:2',
            'randomize_questions' => 'boolean',
            'randomize_options' => 'boolean',
            'max_attempts' => 'integer',
            'status' => CbtExamStatus::class,
            'is_active' => 'boolean',
            'write_to_assessment_score' => 'boolean',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classSectionOffering(): BelongsTo
    {
        return $this->belongsTo(ClassSectionOffering::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function examQuestions(): HasMany
    {
        return $this->hasMany(CbtExamQuestion::class, 'exam_id')->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CbtExamAssignment::class, 'exam_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CbtAttempt::class, 'exam_id');
    }

    public function isPublished(): bool
    {
        return $this->status === CbtExamStatus::Published;
    }

    public function isConfigurationFrozen(): bool
    {
        return $this->isPublished() || $this->status === CbtExamStatus::Archived;
    }
}
