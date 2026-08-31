<?php

namespace App\Http\Requests\People;

use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\StudentStatus;
use App\Models\StudentProfile;
use App\Support\ImageUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentProfile::class) ?? false;
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
        return [
            'admission_number' => ['nullable', 'string', 'max:50', 'unique:student_profiles,admission_number'],
            'surname' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:100'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'state_of_origin' => ['nullable', 'string', 'max:100'],
            'lga' => ['nullable', 'string', 'max:100'],
            'home_address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'user_email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
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
            'photo' => ['required_without:photo_base64', 'file', 'image', 'max:5120'],
            'photo_base64' => ['required_without:photo', 'string', 'min:32'],
            'guardian' => ['nullable', 'array'],
            'guardian.full_name' => ['required_with:guardian', 'string', 'max:255'],
            'guardian.phone' => ['required_with:guardian', 'string', 'max:30'],
            'guardian.email' => array_filter([
                'nullable',
                'email',
                'max:255',
                $this->filled('guardian.password') ? 'required' : null,
                $this->filled('guardian.email') ? Rule::unique('users', 'email') : null,
            ]),
            'guardian.password' => ['nullable', 'string', 'min:8'],
            'password' => ['nullable', 'string', 'min:8'],
            'guardian.occupation' => ['nullable', 'string', 'max:100'],
            'guardian.address' => ['nullable', 'string', 'max:500'],
            'guardian.alternate_phone' => ['nullable', 'string', 'max:30'],
            'guardian.relationship' => ['nullable', Rule::enum(GuardianRelationship::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => 'A pupil photograph is required. Upload a file or capture one with the camera.',
            'photo.required_without' => 'A pupil photograph is required. Upload a file or capture one with the camera.',
            'photo_base64.required_without' => 'A pupil photograph is required. Upload a file or capture one with the camera.',
            'photo.image' => 'The pupil photograph must be an image.',
            'date_of_birth.required' => 'A date of birth is required.',
            'date_of_birth.before' => 'The date of birth must be before today.',
        ];
    }
}
