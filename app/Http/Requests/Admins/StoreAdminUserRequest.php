<?php

namespace App\Http\Requests\Admins;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\User;
use App\Services\AdminUserService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null && app(AdminUserService::class)->actorHas($user, PermissionSlug::AdminsCreate);
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
        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', Rule::in(RoleSlug::appointableDeskRoleValues())],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
