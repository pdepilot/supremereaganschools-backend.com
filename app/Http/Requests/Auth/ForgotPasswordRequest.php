<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuthPortal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('portal') === 'admin') {
            $this->merge(['portal' => AuthPortal::Portal->value]);
        }

        $this->merge([
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'admission_number' => is_string($this->admission_number) ? trim($this->admission_number) : $this->admission_number,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'admission_number' => ['nullable', 'string', 'max:50'],
            'portal' => ['required', 'string', Rule::in([
                AuthPortal::Portal->value,
                AuthPortal::Staff->value,
                AuthPortal::Parent->value,
                AuthPortal::Student->value,
            ])],
        ];
    }

    public function portal(): AuthPortal
    {
        return AuthPortal::from((string) $this->input('portal'));
    }
}
