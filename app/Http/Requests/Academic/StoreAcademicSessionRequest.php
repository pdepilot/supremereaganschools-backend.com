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
