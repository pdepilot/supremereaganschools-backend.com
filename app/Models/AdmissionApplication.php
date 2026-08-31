<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'reference',
    'academic_session_id',
    'session_name',
    'level_id',
    'class_applied',
    'entry_term',
    'surname',
    'first_name',
    'other_names',
    'gender',
    'date_of_birth',
    'nationality',
    'state_of_origin',
    'lga',
    'home_address',
    'previous_school',
    'last_class',
    'parent_name',
    'relationship',
    'parent_phone',
    'parent_email',
    'parent_occupation',
    'parent_second_phone',
    'parent_address',
    'blood_group',
    'genotype',
    'allergies',
    'interests',
    'status',
    'student_profile_id',
])]
class AdmissionApplication extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'relationship' => GuardianRelationship::class,
            'date_of_birth' => 'date',
            'status' => ApplicationStatus::class,
        ];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function fullName(): string
    {
        return trim($this->surname.' '.$this->first_name.' '.($this->other_names ?? ''));
    }
}
