<?php

namespace App\Http\Requests\Academic;

use App\Models\ClassSectionOffering;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassSectionOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('class_section_offering')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ClassSectionOffering $offering */
        $offering = $this->route('class_section_offering');
        $sectionId = $this->integer('class_section_id') ?: $offering->class_section_id;

        return [
            'class_section_id' => ['sometimes', 'integer', 'exists:class_sections,id'],
            'academic_session_id' => [
                'sometimes',
                'integer',
                'exists:academic_sessions,id',
                Rule::unique('class_section_offerings', 'academic_session_id')->where('class_section_id', $sectionId)->ignore($offering),
            ],
            'campus_id' => ['sometimes', 'integer', 'exists:campuses,id'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
