<?php

namespace App\Http\Requests\Fees;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFeeStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('fee_structure')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('amount') && ! $this->filled('amount_kobo')) {
            $this->merge(['amount_kobo' => Money::toKobo($this->input('amount'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fee_type_id' => ['sometimes', 'integer', 'exists:fee_types,id'],
            'academic_session_id' => ['sometimes', 'integer', 'exists:academic_sessions,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'amount_kobo' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
