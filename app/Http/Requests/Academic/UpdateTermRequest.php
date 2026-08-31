<?php

namespace App\Http\Requests\Academic;

use App\Enums\SessionStatus;
use App\Models\Term;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('term')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Term $term */
        $term = $this->route('term');

        return [
            'name' => ['sometimes', 'string', 'max:50', Rule::unique('terms', 'name')->where('academic_session_id', $term->academic_session_id)->ignore($term)],
            'term_number' => ['sometimes', 'integer', 'min:1', 'max:3', Rule::unique('terms', 'term_number')->where('academic_session_id', $term->academic_session_id)->ignore($term)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
        ];
    }
}
