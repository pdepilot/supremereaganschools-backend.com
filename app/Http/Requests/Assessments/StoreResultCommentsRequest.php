<?php

namespace App\Http\Requests\Assessments;

use App\Models\AssessmentScore;
use Illuminate\Foundation\Http\FormRequest;

class StoreResultCommentsRequest extends FormRequest
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
            'term_id' => ['required', 'integer', 'exists:terms,id'],
            'class_teacher_comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'principal_comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
