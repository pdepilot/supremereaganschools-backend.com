<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'assignment_id',
    'student_profile_id',
    'document_id',
    'notes',
    'submitted_at',
])]
class AssignmentSubmission extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isLate(): bool
    {
        $due = $this->assignment?->due_on;
        if ($due === null || $this->submitted_at === null) {
            return false;
        }

        return $this->submitted_at->toDateString() > $due->toDateString();
    }
}
