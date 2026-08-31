<?php

namespace App\Http\Requests\Academic;

use App\Models\ClassSectionOffering;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassSectionOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassSectionOffering::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_section_id' => ['required', 'integer', 'exists:class_sections,id'],
            'academic_session_id' => [
                'required',
                'integer',
                'exists:academic_sessions,id',
                Rule::unique('class_section_offerings', 'academic_session_id')->where('class_section_id', $this->integer('class_section_id')),
            ],
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
            'staff_profile_id' => ['nullable', 'integer', 'exists:staff_profiles,id'],
            'subject_ids' => ['sometimes', 'array'],
            'subject_ids.*' => ['integer', 'exists:subjects,id'],
        ];
    }
}
