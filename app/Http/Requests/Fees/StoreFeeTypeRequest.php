<?php

namespace App\Http\Requests\Fees;

use App\Models\FeeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FeeType::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:fee_types,name'],
            'code' => ['required', 'string', 'max:20', 'unique:fee_types,code'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
