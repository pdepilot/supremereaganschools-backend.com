<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use App\Services\PeopleAccessService;

class RolePolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::RolesView, PermissionSlug::PermissionsView);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::RolesCreate);
    }

    public function update(User $user, Role $role): bool
    {
        if ((string) $role->slug === RoleSlug::SuperAdmin->value && ! $user->hasRole(RoleSlug::SuperAdmin)) {
            return false;
        }

        return $this->access->allows($user, PermissionSlug::RolesEdit);
    }

    public function delete(User $user, Role $role): bool
    {
        if ($role->is_system_role || (string) $role->slug === RoleSlug::SuperAdmin->value) {
            return false;
        }

        return $this->access->allows($user, PermissionSlug::RolesDelete);
    }

    public function assign(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::RolesEdit);
    }
}
