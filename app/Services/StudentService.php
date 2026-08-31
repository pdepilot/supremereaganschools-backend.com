<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\GuardianRelationship;
use App\Enums\RoleSlug;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\GuardianStudent;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\ImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentService
{
    public function __construct(
        private readonly SchoolNumberService $numbers,
        private readonly EnrollmentService $enrollments,
        private readonly GuardianService $guardians,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?int $createdBy = null): StudentProfile
    {
        return DB::transaction(function () use ($attributes, $createdBy) {
            $admissionNumber = $attributes['admission_number'] ?? $this->numbers->nextAdmissionNumber();
            $fullName = trim($attributes['surname'].' '.$attributes['first_name'].' '.($attributes['other_names'] ?? ''));
            $email = $attributes['user_email'] ?? $attributes['email'] ?? $this->numbers->studentLoginEmail($admissionNumber);

            $user = User::query()->create([
                'name' => $fullName,
                'email' => $email,
                'password' => filled($attributes['password'] ?? null) ? $attributes['password'] : Str::password(32),
                'status' => UserStatus::Active,
            ]);
            $user->assignRole(RoleSlug::Student);

            $student = StudentProfile::query()->create([
                'user_id' => $user->id,
                'admission_number' => $admissionNumber,
                'surname' => $attributes['surname'],
                'first_name' => $attributes['first_name'],
                'other_names' => $attributes['other_names'] ?? null,
                'gender' => $attributes['gender'],
                'date_of_birth' => $attributes['date_of_birth'] ?? null,
                'nationality' => $attributes['nationality'] ?? null,
                'state_of_origin' => $attributes['state_of_origin'] ?? null,
                'lga' => $attributes['lga'] ?? null,
                'home_address' => $attributes['home_address'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'email' => $attributes['email'] ?? null,
                'blood_group' => $attributes['blood_group'] ?? null,
                'genotype' => $attributes['genotype'] ?? null,
                'medical_notes' => $attributes['medical_notes'] ?? null,
                'interests' => $attributes['interests'] ?? null,
                'previous_school' => $attributes['previous_school'] ?? null,
                'status' => $attributes['status'] ?? StudentStatus::Active->value,
                'admitted_on' => $attributes['admitted_on'] ?? now()->toDateString(),
                'photo_path' => $this->requirePhoto($attributes['photo'] ?? $attributes['photo_base64'] ?? null),
            ]);

            $this->applyPassphrase($student, $attributes['password'] ?? null);
            $this->syncEnrollment($student, $attributes, $createdBy);
            $this->syncPrimaryGuardian($student, $attributes['guardian'] ?? null);

            return $student->fresh(['user', 'enrollments.classSectionOffering.classSection', 'guardians']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StudentProfile $student, array $attributes): StudentProfile
    {
        return DB::transaction(function () use ($student, $attributes) {
            if (isset($attributes['user_email']) || isset($attributes['surname']) || isset($attributes['first_name']) || isset($attributes['other_names'])) {
                $student->user?->update(array_filter([
                    'email' => $attributes['user_email'] ?? null,
                    'name' => isset($attributes['surname']) || isset($attributes['first_name']) || isset($attributes['other_names'])
                        ? trim(($attributes['surname'] ?? $student->surname).' '.($attributes['first_name'] ?? $student->first_name).' '.($attributes['other_names'] ?? $student->other_names ?? ''))
                        : null,
                ]));
            }

            $guardian = $attributes['guardian'] ?? null;
            $this->syncEnrollment($student, $attributes);
            if (isset($attributes['photo']) || isset($attributes['photo_base64'])) {
                $attributes['photo_path'] = $this->storePhoto(
                    $student->photo_path,
                    $attributes['photo'] ?? $attributes['photo_base64'] ?? null,
                );
            }
            $this->applyPassphrase($student, $attributes['password'] ?? null);
            unset(
                $attributes['password'],
                $attributes['user_email'],
                $attributes['class_section_id'],
                $attributes['academic_session_id'],
                $attributes['school_class_id'],
                $attributes['enrolled_on'],
                $attributes['guardian'],
                $attributes['photo'],
                $attributes['photo_base64'],
            );
            $student->update($attributes);
            $this->syncPrimaryGuardian($student->fresh(['guardians']), $guardian);

            return $student->fresh(['user', 'enrollments.classSectionOffering.classSection', 'guardians']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncEnrollment(StudentProfile $student, array $attributes, ?int $createdBy = null): void
    {
        if (empty($attributes['class_section_id']) || empty($attributes['academic_session_id'])) {
            return;
        }

        $sessionId = (int) $attributes['academic_session_id'];
        $enrollment = Enrollment::query()
            ->where('student_profile_id', $student->id)
            ->where('academic_session_id', $sessionId)
            ->first();

        if ($enrollment) {
            $this->enrollments->update($enrollment, [
                'class_section_id' => $attributes['class_section_id'],
                'academic_session_id' => $sessionId,
                'school_class_id' => $attributes['school_class_id'] ?? null,
            ]);

            return;
        }

        $this->enrollments->create([
            'student_profile_id' => $student->id,
            'class_section_id' => $attributes['class_section_id'],
            'academic_session_id' => $sessionId,
            'school_class_id' => $attributes['school_class_id'] ?? null,
            'enrolled_on' => $attributes['enrolled_on'] ?? $student->admitted_on?->toDateString() ?? now()->toDateString(),
        ], $createdBy);
    }

    /**
     * @param  array<string, mixed>|null  $guardian
     */
    private function syncPrimaryGuardian(StudentProfile $student, ?array $guardian): void
    {
        $name = trim((string) ($guardian['full_name'] ?? ''));
        if ($name === '') {
            return;
        }

        $payload = array_filter([
            'full_name' => $name,
            'phone' => $guardian['phone'] ?? null,
            'alternate_phone' => $guardian['alternate_phone'] ?? null,
            'email' => $guardian['email'] ?? null,
            'occupation' => $guardian['occupation'] ?? null,
            'address' => $guardian['address'] ?? null,
            'password' => $guardian['password'] ?? null,
            'relationship' => $guardian['relationship'] ?? GuardianRelationship::Guardian->value,
        ], fn ($value) => $value !== null && $value !== '');

        $student->loadMissing('guardians');
        $existing = $student->guardians->firstWhere('pivot.is_primary', true)
            ?? $student->guardians->first();

        if ($existing) {
            $this->guardians->update($existing, $payload);
            if (isset($payload['relationship'])) {
                GuardianStudent::query()
                    ->where('guardian_profile_id', $existing->id)
                    ->where('student_profile_id', $student->id)
                    ->update([
                        'relationship' => $payload['relationship'],
                        'is_primary' => true,
                    ]);
            }

            return;
        }

        $this->guardians->create(array_merge($payload, [
            'student_profile_id' => $student->id,
            'is_primary' => true,
            'can_login' => true,
        ]));
    }

    private function requirePhoto(mixed $photo): string
    {
        $path = $this->storePhoto(null, $photo);
        if (! $path) {
            throw ValidationException::withMessages([
                'photo' => 'A pupil photograph is required. Upload a file or capture one with the camera.',
            ]);
        }

        return $path;
    }

    private function storePhoto(?string $previous, mixed $photo): ?string
    {
        $file = $photo instanceof UploadedFile
            ? $photo
            : (is_string($photo) ? ImageUpload::fromBase64($photo) : null);

        if (! $file instanceof UploadedFile) {
            return $previous;
        }

        $path = $file->store('students/photos', 'local');
        $this->forgetPhoto($previous);

        return $path ?: $previous;
    }

    private function forgetPhoto(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    public function delete(StudentProfile $student): void
    {
        DB::transaction(function () use ($student) {
            Enrollment::query()
                ->where('student_profile_id', $student->id)
                ->where('status', EnrollmentStatus::Active)
                ->update([
                    'status' => EnrollmentStatus::Withdrawn,
                    'left_on' => now()->toDateString(),
                ]);

            $student->user?->update(['status' => UserStatus::Inactive]);
            $student->update(['status' => StudentStatus::Withdrawn]);
            $this->forgetPhoto($student->photo_path);
            $student->delete();
        });
    }

    public function suspend(StudentProfile $student): StudentProfile
    {
        return DB::transaction(function () use ($student) {
            $student->user?->update(['status' => UserStatus::Suspended]);
            $student->update(['status' => StudentStatus::Inactive]);

            return $student->fresh(['user', 'enrollments.classSectionOffering.classSection', 'guardians', 'invoices']);
        });
    }

    public function reinstate(StudentProfile $student): StudentProfile
    {
        return DB::transaction(function () use ($student) {
            $student->user?->update(['status' => UserStatus::Active]);
            $student->update(['status' => StudentStatus::Active]);

            return $student->fresh(['user', 'enrollments.classSectionOffering.classSection', 'guardians', 'invoices']);
        });
    }

    private function applyPassphrase(StudentProfile $student, mixed $password): void
    {
        $text = is_string($password) ? trim($password) : '';

        if ($text === '') {
            return;
        }

        $student->user?->update(['password' => $text]);
        $student->forceFill(['passphrase_set_at' => now()])->save();
    }
}
