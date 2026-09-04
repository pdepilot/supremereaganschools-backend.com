<?php

namespace App\Http\Requests\Admins;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(RoleSlug::SuperAdmin)
            && $this->user()->status === UserStatus::Active;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(collect(PermissionSlug::assignable())->map->value->all())],
        ];
    }
}
