<?php

namespace App\Http\Requests\Academic;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicSession::class) ?? false;
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'That year is still on the ledger (including archived years). Delete it first, or choose a different name.',
            'ends_on.after_or_equal' => 'The end date must be on or after the start date.',
            'term_count.in' => 'A year must have 2 or 3 terms (usually 3). Put 3 here, then Seal First Term after the year is live.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', 'unique:academic_sessions,name'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'term_count' => ['required', 'integer', 'in:2,3'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
        ];
    }
}
