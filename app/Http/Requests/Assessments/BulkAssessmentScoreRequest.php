<?php

namespace App\Http\Requests\Assessments;

use App\Models\AssessmentScore;
use Illuminate\Foundation\Http\FormRequest;

class BulkAssessmentScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AssessmentScore::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_section_offering_id' => ['required', 'integer', 'exists:class_section_offerings,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'assessment_type_id' => ['required', 'integer', 'exists:assessment_types,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'academic_session_id' => ['nullable', 'integer', 'exists:academic_sessions,id', 'required_without:term_id'],
            'scores' => ['required', 'array', 'min:1'],
            'scores.*.enrollment_id' => ['required', 'integer', 'exists:enrollments,id', 'distinct'],
            'scores.*.score' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
