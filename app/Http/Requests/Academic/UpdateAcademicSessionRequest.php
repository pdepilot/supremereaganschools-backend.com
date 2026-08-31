<?php

namespace App\Http\Requests\Academic;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_session')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var AcademicSession $session */
        $session = $this->route('academic_session');

        return [
            'name' => ['sometimes', 'string', 'max:50', Rule::unique('academic_sessions', 'name')->ignore($session)],
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'date', 'after_or_equal:starts_on'],
            'term_count' => ['sometimes', 'integer', 'in:2,3'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
        ];
    }
}
