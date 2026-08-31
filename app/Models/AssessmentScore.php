<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enrollment_id',
    'term_id',
    'subject_id',
    'assessment_type_id',
    'score',
    'entered_by',
])]
class AssessmentScore extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
