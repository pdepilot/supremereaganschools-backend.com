<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

#[Fillable([
    'user_id',
    'admission_number',
    'surname',
    'first_name',
    'other_names',
    'gender',
    'date_of_birth',
    'nationality',
    'state_of_origin',
    'lga',
    'home_address',
    'phone',
    'email',
    'blood_group',
    'genotype',
    'medical_notes',
    'interests',
    'previous_school',
    'status',
    'admitted_on',
    'photo_path',
    'passphrase_set_at',
])]
class StudentProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'admitted_on' => 'date',
            'status' => StudentStatus::class,
            'passphrase_set_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function currentEnrollment(): HasMany
    {
        return $this->hasMany(Enrollment::class)->where('status', EnrollmentStatus::Active);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(GuardianProfile::class, 'guardian_student')
            ->withPivot(['id', 'relationship', 'is_primary', 'can_login'])
            ->withTimestamps();
    }

    public function guardianLinks(): HasMany
    {
        return $this->hasMany(GuardianStudent::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function admissionApplications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class);
    }

    public function fullName(): string
    {
        return trim($this->surname.' '.$this->first_name.' '.($this->other_names ?? ''));
    }

    public function matchesLoginName(string $identifier): bool
    {
        $needle = $this->normalizeLoginName($identifier);

        if ($needle === '') {
            return false;
        }

        $haystacks = [
            $this->fullName(),
            trim($this->first_name.' '.$this->surname),
            trim($this->surname.' '.$this->first_name),
        ];

        if (filled($this->other_names)) {
            $haystacks[] = trim($this->first_name.' '.$this->other_names.' '.$this->surname);
            $haystacks[] = trim($this->surname.' '.$this->first_name.' '.$this->other_names);
        }

        if (filled($this->user?->name)) {
            $haystacks[] = $this->user->name;
        }

        foreach ($haystacks as $name) {
            if ($this->normalizeLoginName((string) $name) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function normalizeLoginName(string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    public function hasPassphrase(): bool
    {
        return $this->passphrase_set_at !== null;
    }

    public function guardianPhoneMatches(string $attempt): bool
    {
        $this->loadMissing('guardians');

        return $this->guardians->contains(function (GuardianProfile $guardian) use ($attempt) {
            foreach ([$guardian->phone, $guardian->alternate_phone] as $phone) {
                if (is_string($phone) && $phone !== '' && Phone::matches($attempt, $phone)) {
                    return true;
                }
            }

            return false;
        });
    }

    public function secretMatches(string $attempt): bool
    {
        $this->loadMissing('user');

        if ($this->user && Hash::check($attempt, $this->user->getAuthPassword())) {
            return true;
        }

        if ($this->hasPassphrase()) {
            return false;
        }

        return $this->guardianPhoneMatches($attempt);
    }
}
