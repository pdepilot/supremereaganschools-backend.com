<?php

namespace App\Http\Requests\Fees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('fee_type')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->route('fee_type');

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('fee_types', 'name')->ignore($type)],
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('fee_types', 'code')->ignore($type)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
