<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kind = (string) $this->input('kind', 'roll');

        return [
            'kind' => ['required', Rule::in(['roll', 'attendance', 'marks', 'results'])],
            'class_section_offering_id' => ['required', 'integer', 'exists:class_section_offerings,id'],
            'subject_id' => [
                Rule::requiredIf(in_array($kind, ['marks', 'results'], true)),
                'nullable',
                'integer',
                'exists:subjects,id',
            ],
            'assessment_type_id' => [
                Rule::requiredIf($kind === 'marks'),
                'nullable',
                'integer',
                'exists:assessment_types,id',
            ],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'academic_session_id' => ['nullable', 'integer', 'exists:academic_sessions,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
