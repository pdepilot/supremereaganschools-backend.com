<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Models\AcademicSession;
use App\Models\AdmissionApplication;
use App\Models\Level;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ApplicationService
{
    public function __construct(
        private readonly SchoolNumberService $numbers,
        private readonly DocumentService $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, UploadedFile|null>  $files
     */
    public function submit(array $attributes, array $files = []): AdmissionApplication
    {
        return DB::transaction(function () use ($attributes, $files) {
            $application = AdmissionApplication::query()->create([
                'reference' => $this->numbers->nextApplicationReference(),
                'academic_session_id' => $this->matchSession($attributes['session_name'] ?? null),
                'session_name' => $attributes['session_name'],
                'level_id' => $this->matchLevel($attributes['level'] ?? null, $attributes['class_applied'] ?? null),
                'class_applied' => $attributes['class_applied'],
                'entry_term' => $attributes['entry_term'],
                'surname' => $attributes['surname'],
                'first_name' => $attributes['first_name'],
                'other_names' => $attributes['other_names'] ?? null,
                'gender' => $attributes['gender'],
                'date_of_birth' => $attributes['date_of_birth'],
                'nationality' => $attributes['nationality'],
                'state_of_origin' => $attributes['state_of_origin'],
                'lga' => $attributes['lga'] ?? null,
                'home_address' => $attributes['home_address'],
                'previous_school' => $attributes['previous_school'] ?? null,
                'last_class' => $attributes['last_class'] ?? null,
                'parent_name' => $attributes['parent_name'],
                'relationship' => $attributes['relationship'],
                'parent_phone' => $attributes['parent_phone'],
                'parent_email' => $attributes['parent_email'],
                'parent_occupation' => $attributes['parent_occupation'] ?? null,
                'parent_second_phone' => $attributes['parent_second_phone'] ?? null,
                'parent_address' => $attributes['parent_address'] ?? null,
                'blood_group' => $attributes['blood_group'] ?? null,
                'genotype' => $attributes['genotype'] ?? null,
                'allergies' => $attributes['allergies'] ?? null,
                'interests' => $attributes['interests'] ?? null,
                'status' => ApplicationStatus::Submitted,
            ]);

            $this->attachFiles($application, $files);

            return $application->load('documents', 'level', 'academicSession');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AdmissionApplication $application, array $attributes, User $actor): AdmissionApplication
    {
        unset($actor);

        if (isset($attributes['status'])) {
            $application->status = ApplicationStatus::from($attributes['status']);
        }

        $application->save();

        return $application->fresh(['documents', 'level', 'academicSession']);
    }

    public function markOpened(AdmissionApplication $application): AdmissionApplication
    {
        if ($application->status === ApplicationStatus::Submitted) {
            $application->update(['status' => ApplicationStatus::UnderReview]);
        }

        return $application->fresh(['documents', 'level', 'academicSession']);
    }

    /**
     * @param  array<string, UploadedFile|null>  $files
     */
    private function attachFiles(AdmissionApplication $application, array $files): void
    {
        $map = [
            'passport_photo' => DocumentType::PassportPhoto,
            'birth_certificate' => DocumentType::BirthCertificate,
            'exam_receipt' => DocumentType::ExamReceipt,
        ];

        foreach ($map as $key => $type) {
            $file = $files[$key] ?? null;
            if ($file instanceof UploadedFile && $file->isValid()) {
                $this->documents->store($application, $file, $type);
            }
        }
    }

    private function matchSession(?string $name): ?int
    {
        if (blank($name)) {
            return null;
        }

        $normalized = str_replace(' ', '', strtolower($name));

        return AcademicSession::query()
            ->get()
            ->first(fn (AcademicSession $session) => str_replace(' ', '', strtolower($session->name)) === $normalized)
            ?->id;
    }

    private function matchLevel(?string $level, ?string $classApplied): ?int
    {
        $haystack = strtolower(trim(($level ?? '').' '.($classApplied ?? '')));

        $slug = match (true) {
            str_contains($haystack, 'nursery') => 'nursery',
            str_contains($haystack, 'primary') => 'primary',
            str_contains($haystack, 'senior') || (bool) preg_match('/\bss\s*\d/', $haystack) => 'ss',
            str_contains($haystack, 'junior') || str_contains($haystack, 'jss') || str_contains($haystack, 'secondary') => 'jss',
            default => null,
        };

        if ($slug === null) {
            return null;
        }

        return Level::query()->where('slug', $slug)->value('id');
    }
}
