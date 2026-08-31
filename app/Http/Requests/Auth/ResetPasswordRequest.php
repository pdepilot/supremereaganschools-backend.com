<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuthPortal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResetPasswordRequest extends FormRequest
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
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'portal' => ['required', 'string', Rule::in([
                AuthPortal::Portal->value,
                AuthPortal::Staff->value,
                AuthPortal::Parent->value,
            ])],
        ];
    }

    public function portal(): AuthPortal
    {
        return AuthPortal::from((string) $this->input('portal'));
    }
}
