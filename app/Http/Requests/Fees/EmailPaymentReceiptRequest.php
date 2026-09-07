<?php

namespace App\Http\Requests\Fees;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

class EmailPaymentReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Payment $payment */
        $payment = $this->route('payment');

        return $this->user()?->can('update', $payment) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }

        if (is_string($this->name)) {
            $this->merge(['name' => trim($this->name)]);
        }
    }
}
