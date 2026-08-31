<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuthPortal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
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

        $identifier = $this->input('identifier', $this->input('admission_number'));

        $this->merge([
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'admission_number' => is_string($identifier) ? trim($identifier) : $identifier,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $portal = $this->portal();
        $household = $portal === AuthPortal::Student
            || ($portal === AuthPortal::Parent && filled($this->input('admission_number')));

        return [
            'email' => [
                Rule::requiredIf(! $household && $portal !== AuthPortal::Student),
                'nullable',
                'email',
                'max:255',
            ],
            'admission_number' => [
                Rule::requiredIf($portal === AuthPortal::Student || ($portal === AuthPortal::Parent && ! filled($this->input('email')))),
                'nullable',
                'string',
                'max:50',
            ],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
            'portal' => ['sometimes', 'string', Rule::enum(AuthPortal::class)],
        ];
    }

    public function portal(): AuthPortal
    {
        $value = $this->input('portal', AuthPortal::Portal->value);

        return AuthPortal::from($value);
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }

    public function throttleIdentifier(): string
    {
        if ($this->portal() === AuthPortal::Student
            || ($this->portal() === AuthPortal::Parent && filled($this->input('admission_number')))) {
            return (string) $this->input('admission_number', '');
        }

        return (string) $this->input('email', '');
    }
}
