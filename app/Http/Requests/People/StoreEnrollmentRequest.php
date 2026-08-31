<?php

namespace App\Http\Requests\People;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Enrollment::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_profile_id' => ['required', 'integer', 'exists:student_profiles,id'],
            'academic_session_id' => ['required_without:class_section_offering_id', 'nullable', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'class_section_id' => ['required_without:class_section_offering_id', 'nullable', 'integer', 'exists:class_sections,id'],
            'class_section_offering_id' => ['nullable', 'integer', 'exists:class_section_offerings,id'],
            'status' => ['sometimes', Rule::enum(EnrollmentStatus::class)],
            'enrolled_on' => ['sometimes', 'date'],
            'left_on' => ['nullable', 'date', 'after_or_equal:enrolled_on'],
        ];
    }
}
