<?php

namespace App\Http\Requests\Account;

use App\Enums\AuthPortal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge([
                'email' => strtolower(trim($this->email)),
            ]);
        }

        // Staff desk accounts keep the office-issued name; ignore client renames.
        if ($this->isStaffDeskAccount()) {
            $this->request->remove('name');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isStaffDeskAccount()) {
            return [
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($this->user()?->id),
                ],
            ];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],
        ];
    }

    private function isStaffDeskAccount(): bool
    {
        $user = $this->user();

        return $user !== null
            && AuthPortal::Staff->admits($user)
            && ! AuthPortal::Portal->admits($user);
    }
}
