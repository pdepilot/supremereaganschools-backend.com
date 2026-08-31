<?php

namespace App\Http\Requests\Academic;

use App\Enums\SessionStatus;
use App\Models\Term;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Term::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sessionId = $this->route('academic_session')?->id;

        return [
            'name' => ['required', 'string', 'max:50', Rule::unique('terms', 'name')->where('academic_session_id', $sessionId)],
            'term_number' => ['required', 'integer', 'min:1', 'max:3', Rule::unique('terms', 'term_number')->where('academic_session_id', $sessionId)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
        ];
    }
}
