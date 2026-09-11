<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\GuardianRelationship;
use App\Enums\RoleSlug;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\ClassSection;
use App\Models\Enrollment;
use App\Models\GuardianProfile;
use App\Models\GuardianStudent;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\ImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentService
{
    public function __construct(
        private readonly SchoolNumberService $numbers,
        private readonly EnrollmentService $enrollments,
        private readonly GuardianService $guardians,
        private readonly PupilRegistrationMailer $registrationMailer,
        private readonly RbacService $rbac,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?int $createdBy = null): StudentProfile
    {
        $student = DB::transaction(function () use ($attributes, $createdBy) {
            $wing = $this->resolveWingSlug($attributes);
            $admissionNumber = $attributes['admission_number']
                ?? $this->numbers->nextAdmissionNumber(wing: $wing);
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

        $this->registrationMailer->notifyGuardian($student);

        return $student;
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
     * Resolve nursery / primary / secondary (or activity) from the chosen class.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resolveWingSlug(array $attributes): ?string
    {
        if (filled($attributes['wing'] ?? null)) {
            return (string) $attributes['wing'];
        }

        if (filled($attributes['level_id'] ?? null)) {
            return Level::query()->whereKey($attributes['level_id'])->value('slug');
        }

        if (filled($attributes['school_class_id'] ?? null)) {
            return SchoolClass::query()
                ->with('level')
                ->find($attributes['school_class_id'])
                ?->level
                ?->slug;
        }

        if (filled($attributes['class_section_id'] ?? null)) {
            return ClassSection::query()
                ->with('schoolClass.level')
                ->find($attributes['class_section_id'])
                ?->schoolClass
                ?->level
                ?->slug;
        }

        return null;
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

        // Same parent/guardian email (or phone) across siblings — reuse and link.
        $shared = $this->guardians->findExisting(
            $payload['email'] ?? null,
            $payload['phone'] ?? null,
        );

        if ($shared !== null) {
            $this->assertEmailIsAvailableForParent($payload['email'] ?? null, $shared);

            $update = $payload;
            unset($update['password']); // keep the existing parent login passphrase
            $this->guardians->update($shared, $update);
            $this->guardians->link($shared, [
                'student_profile_id' => $student->id,
                'relationship' => $payload['relationship'] ?? GuardianRelationship::Guardian->value,
                'is_primary' => true,
                'can_login' => true,
            ]);

            return;
        }

        $this->assertEmailIsAvailableForParent($payload['email'] ?? null);

        $this->guardians->create(array_merge($payload, [
            'student_profile_id' => $student->id,
            'is_primary' => true,
            'can_login' => true,
        ]));
    }

    private function assertEmailIsAvailableForParent(?string $email, ?GuardianProfile $forGuardian = null): void
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user === null) {
            return;
        }

        if ($forGuardian?->user_id && (int) $forGuardian->user_id === (int) $user->id) {
            return;
        }

        if ($user->hasRole(RoleSlug::Parent) || GuardianProfile::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'guardian.email' => 'This email belongs to another school account and cannot be used for a parent login.',
        ]);
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

    public function delete(StudentProfile $student, User $actor): void
    {
        DB::transaction(function () use ($student, $actor) {
            $student->load([
                'user',
                'guardians.user',
                'guardianLinks',
                'enrollments',
            ]);

            $this->rbac->audit($actor, 'pupil.deleted', $student, $this->deletionAuditMeta($student));

            Enrollment::query()
                ->where('student_profile_id', $student->id)
                ->where('status', EnrollmentStatus::Active)
                ->update([
                    'status' => EnrollmentStatus::Withdrawn,
                    'left_on' => now()->toDateString(),
                ]);

            $guardians = $student->guardians->all();
            GuardianStudent::query()->where('student_profile_id', $student->id)->delete();

            foreach ($guardians as $guardian) {
                $this->releaseOrphanGuardian($guardian);
            }

            $this->forgetPhoto($student->photo_path);
            $this->scrubAndArchivePupil($student);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function deletionAuditMeta(StudentProfile $student): array
    {
        return [
            'admission_number' => $student->admission_number,
            'surname' => $student->surname,
            'first_name' => $student->first_name,
            'other_names' => $student->other_names,
            'gender' => $student->gender?->value ?? $student->gender,
            'date_of_birth' => optional($student->date_of_birth)?->toDateString(),
            'phone' => $student->phone,
            'email' => $student->email,
            'home_address' => $student->home_address,
            'status' => $student->status?->value ?? $student->status,
            'user' => $student->user ? [
                'id' => $student->user->id,
                'email' => $student->user->email,
                'name' => $student->user->name,
            ] : null,
            'guardians' => $student->guardians->map(fn (GuardianProfile $guardian) => [
                'id' => $guardian->id,
                'full_name' => $guardian->full_name,
                'phone' => $guardian->phone,
                'alternate_phone' => $guardian->alternate_phone,
                'email' => $guardian->email,
                'occupation' => $guardian->occupation,
                'address' => $guardian->address,
                'relationship' => $guardian->pivot?->relationship instanceof \BackedEnum
                    ? $guardian->pivot->relationship->value
                    : $guardian->pivot?->relationship,
                'is_primary' => (bool) ($guardian->pivot?->is_primary),
                'user' => $guardian->user ? [
                    'id' => $guardian->user->id,
                    'email' => $guardian->user->email,
                    'name' => $guardian->user->name,
                ] : null,
            ])->values()->all(),
            'enrollment_ids' => $student->enrollments->pluck('id')->values()->all(),
        ];
    }

    private function scrubAndArchivePupil(StudentProfile $student): void
    {
        $user = $student->user;
        $originalAdmission = (string) $student->admission_number;
        $freedAdmission = $this->freedAdmissionNumber($student->id, $originalAdmission);

        $student->forceFill([
            'user_id' => null,
            'admission_number' => $freedAdmission,
            'surname' => 'Removed',
            'first_name' => 'Pupil',
            'other_names' => null,
            'date_of_birth' => null,
            'nationality' => null,
            'state_of_origin' => null,
            'lga' => null,
            'home_address' => null,
            'phone' => null,
            'email' => null,
            'blood_group' => null,
            'genotype' => null,
            'medical_notes' => null,
            'interests' => null,
            'previous_school' => null,
            'photo_path' => null,
            'passphrase_set_at' => null,
            'status' => StudentStatus::Withdrawn,
        ])->save();

        $student->delete();

        if ($user) {
            $this->releaseLoginAccount($user, 'pupil');
        }
    }

    private function releaseOrphanGuardian(GuardianProfile $guardian): void
    {
        $stillLinked = GuardianStudent::query()
            ->where('guardian_profile_id', $guardian->id)
            ->whereHas('student', fn ($q) => $q->whereNull('deleted_at'))
            ->exists();

        if ($stillLinked) {
            return;
        }

        $user = $guardian->user;

        $guardian->forceFill([
            'user_id' => null,
            'full_name' => 'Removed guardian',
            'phone' => null,
            'alternate_phone' => null,
            'email' => null,
            'occupation' => null,
            'address' => null,
        ])->save();

        $guardian->delete();

        if ($user) {
            $this->releaseLoginAccount($user, 'guardian');
        }
    }

    private function releaseLoginAccount(User $user, string $kind): void
    {
        $this->invalidateSessions($user);
        $user->roles()->detach();

        $user->forceFill([
            'status' => UserStatus::Inactive,
            'email' => sprintf('deleted+%s-%d-%s@removed.local', $kind, $user->id, Str::lower(Str::random(8))),
            'name' => 'Removed '.$kind,
            'password' => Str::password(32),
            'remember_token' => Str::random(60),
        ])->save();

        try {
            $user->delete();
        } catch (\Throwable) {
            // Keep the tombstoned row when historical records still reference the user.
        }
    }

    private function freedAdmissionNumber(int $studentId, string $original): string
    {
        $stamp = 'DEL-'.$studentId.'-';
        $remaining = max(1, 255 - strlen($stamp));

        return $stamp.substr($original, 0, $remaining);
    }

    private function invalidateSessions(User $user): void
    {
        $user->forceFill([
            'remember_token' => Str::random(60),
        ])->save();

        if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }
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
