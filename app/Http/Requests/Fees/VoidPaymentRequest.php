<?php

namespace App\Http\Requests\Fees;

use Illuminate\Foundation\Http\FormRequest;

class VoidPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('payment')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'max:255'],
        ];
    }
}
