<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enrollment_id',
    'term_id',
    'average',
    'class_position',
    'class_size',
    'class_teacher_comment',
    'principal_comment',
    'class_teacher_commented_by',
    'principal_commented_by',
])]
class TermSummary extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'average' => 'decimal:2',
            'class_position' => 'integer',
            'class_size' => 'integer',
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
}
