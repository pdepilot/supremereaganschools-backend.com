<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'student_profile_id',
    'from_enrollment_id',
    'to_enrollment_id',
    'academic_session_id',
    'decision',
    'decided_by',
])]
class Promotion extends Model
{
    use HasFactory;

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function fromEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'from_enrollment_id');
    }

    public function toEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'to_enrollment_id');
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
