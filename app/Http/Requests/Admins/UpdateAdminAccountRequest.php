<?php

namespace App\Http\Requests\Admins;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\PeopleAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $admin = $this->route('admin');

        if (! $actor instanceof User || ! $admin instanceof User) {
            return false;
        }

        if (! app(PeopleAccessService::class)->administers($actor)) {
            return false;
        }

        if ($this->exists('permissions') || $this->exists('status')) {
            return $actor->hasRole(RoleSlug::SuperAdmin);
        }

        if ($admin->hasRole(RoleSlug::SuperAdmin) && ! $actor->hasRole(RoleSlug::SuperAdmin)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $admin */
        $admin = $this->route('admin');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($admin->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(collect(PermissionSlug::assignable())->map->value->all())],
        ];
    }
}
