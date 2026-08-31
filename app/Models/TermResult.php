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
    'ca_total',
    'exam_score',
    'total',
    'grade',
    'remark',
])]
class TermResult extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ca_total' => 'decimal:2',
            'exam_score' => 'decimal:2',
            'total' => 'decimal:2',
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
}
