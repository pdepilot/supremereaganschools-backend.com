<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCbtLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', \App\Models\SchoolSetting::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('cbt_login_email') && is_string($this->input('cbt_login_email'))) {
            $this->merge([
                'cbt_login_email' => strtolower(trim($this->input('cbt_login_email'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cbt_login_email' => ['required', 'email', 'max:255'],
            'cbt_login_password' => ['nullable', 'string', 'min:8', 'max:255'],
            'cbt_operator_user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
