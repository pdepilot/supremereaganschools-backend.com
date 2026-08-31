<?php

namespace App\Http\Requests\People;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('enrollment')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_session_id' => ['sometimes', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'class_section_id' => ['sometimes', 'integer', 'exists:class_sections,id'],
            'class_section_offering_id' => ['sometimes', 'integer', 'exists:class_section_offerings,id'],
            'status' => ['sometimes', Rule::enum(EnrollmentStatus::class)],
            'enrolled_on' => ['sometimes', 'date'],
            'left_on' => ['nullable', 'date'],
        ];
    }
}
