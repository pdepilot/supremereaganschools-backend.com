<?php

namespace App\Http\Requests\People;

use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\StudentStatus;
use App\Models\StudentProfile;
use App\Support\ImageUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('student_profile')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->hasFile('photo') || ! $this->filled('photo_base64')) {
            return;
        }

        $file = ImageUpload::fromBase64((string) $this->input('photo_base64'));
        if ($file) {
            $this->files->set('photo', $file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var StudentProfile $student */
        $student = $this->route('student_profile');

        return [
            'admission_number' => ['sometimes', 'string', 'max:50', Rule::unique('student_profiles', 'admission_number')->ignore($student)],
            'surname' => ['sometimes', 'string', 'max:100'],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:100'],
            'gender' => ['sometimes', Rule::enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'state_of_origin' => ['nullable', 'string', 'max:100'],
            'lga' => ['nullable', 'string', 'max:100'],
            'home_address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'user_email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student->user_id)],
            'blood_group' => ['nullable', 'string', 'max:5'],
            'genotype' => ['nullable', 'string', 'max:5'],
            'medical_notes' => ['nullable', 'string', 'max:2000'],
            'interests' => ['nullable', 'string', 'max:255'],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(StudentStatus::class)],
            'admitted_on' => ['nullable', 'date'],
            'academic_session_id' => ['nullable', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'class_section_id' => ['nullable', 'integer', 'exists:class_sections,id', 'required_with:academic_session_id'],
            'enrolled_on' => ['nullable', 'date'],
            'photo' => ['nullable', 'file', 'image', 'max:5120'],
            'photo_base64' => ['nullable', 'string'],
            'guardian' => ['nullable', 'array'],
            'guardian.full_name' => ['required_with:guardian', 'string', 'max:255'],
            'guardian.phone' => ['nullable', 'string', 'max:30'],
            'guardian.email' => array_filter([
                'nullable',
                'email',
                'max:255',
                $this->filled('guardian.password') ? 'required' : null,
                $this->filled('guardian.email') ? Rule::unique('users', 'email')->ignore($this->primaryGuardianUserId()) : null,
            ]),
            'guardian.password' => ['nullable', 'string', 'min:8'],
            'password' => ['nullable', 'string', 'min:8'],
            'guardian.occupation' => ['nullable', 'string', 'max:100'],
            'guardian.address' => ['nullable', 'string', 'max:500'],
            'guardian.alternate_phone' => ['nullable', 'string', 'max:30'],
            'guardian.relationship' => ['nullable', Rule::enum(GuardianRelationship::class)],
        ];
    }

    private function primaryGuardianUserId(): ?int
    {
        /** @var StudentProfile|null $student */
        $student = $this->route('student_profile');
        if (! $student instanceof StudentProfile) {
            return null;
        }

        $student->loadMissing('guardians');
        $guardian = $student->guardians->firstWhere('pivot.is_primary', true) ?? $student->guardians->first();

        return $guardian?->user_id;
    }
}
