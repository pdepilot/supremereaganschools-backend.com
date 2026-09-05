<?php

namespace App\Http\Requests\Admins;

use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $actor */
        $actor = $this->user();
        /** @var User $admin */
        $admin = $this->route('admin');

        return $actor !== null && $actor->can('update', $admin);
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if (is_string($this->email)) {
            $payload['email'] = strtolower(trim($this->email));
        }

        if (is_string($this->first_name)) {
            $payload['first_name'] = trim($this->first_name);
        }

        if (is_string($this->last_name)) {
            $payload['last_name'] = trim($this->last_name);
        }

        if (is_string($this->role)) {
            $payload['role'] = trim($this->role);
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $admin */
        $admin = $this->route('admin');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:120'],
            'last_name' => ['sometimes', 'required', 'string', 'max:120'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($admin->id),
            ],
            'role' => ['sometimes', 'required', 'string', Rule::in(RoleSlug::appointableDeskRoleValues())],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
        ];
    }
}
