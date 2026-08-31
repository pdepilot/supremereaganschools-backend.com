<?php

namespace App\Http\Requests\Fees;

use App\Models\FeeStructure;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class StoreFeeStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FeeStructure::class) ?? false;
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
            'fee_type_id' => ['required', 'integer', 'exists:fee_types,id'],
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'level_id' => ['nullable', 'integer', 'exists:levels,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'amount_kobo' => ['required', 'integer', 'min:1'],
        ];
    }
}
