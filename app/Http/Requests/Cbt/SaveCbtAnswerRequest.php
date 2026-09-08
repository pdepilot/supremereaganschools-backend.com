<?php

namespace App\Http\Requests\Cbt;

use Illuminate\Foundation\Http\FormRequest;

class SaveCbtAnswerRequest extends FormRequest
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
        return [
            'exam_question_id' => ['required', 'integer', 'exists:cbt_exam_questions,id'],
            'selected_exam_option_id' => ['sometimes', 'nullable', 'integer', 'exists:cbt_exam_question_options,id'],
            'client_answered_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
