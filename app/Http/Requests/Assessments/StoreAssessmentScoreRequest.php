<?php

namespace App\Http\Requests\Assessments;

use App\Models\AssessmentScore;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentScoreRequest extends FormRequest
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
            'enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'assessment_type_id' => ['required', 'integer', 'exists:assessment_types,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'academic_session_id' => ['nullable', 'integer', 'exists:academic_sessions,id', 'required_without:term_id'],
            'score' => ['required', 'numeric', 'min:0'],
        ];
    }
}
