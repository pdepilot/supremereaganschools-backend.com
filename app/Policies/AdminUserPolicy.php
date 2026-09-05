<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AdminUserService;

class AdminUserPolicy
{
    public function __construct(private readonly AdminUserService $admins) {}

    public function viewAny(User $user): bool
    {
        return $this->admins->actorHas($user, PermissionSlug::AdminsView);
    }

    public function view(User $user, User $admin): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($admin->hasRole(RoleSlug::SuperAdmin) && ! $user->hasRole(RoleSlug::SuperAdmin)) {
            return false;
        }

        return $admin->hasAnyRole(...RoleSlug::appointableDeskRoles());
    }

    public function create(User $user): bool
    {
        return $this->admins->actorHas($user, PermissionSlug::AdminsCreate);
    }

    public function update(User $user, User $admin): bool
    {
        if (! $this->admins->actorHas($user, PermissionSlug::AdminsEdit)) {
            return false;
        }

        if ($admin->hasRole(RoleSlug::SuperAdmin) && ! $user->hasRole(RoleSlug::SuperAdmin)) {
            return false;
        }

        return $admin->hasAnyRole(...RoleSlug::appointableDeskRoles());
    }

    public function suspend(User $user, User $admin): bool
    {
        if (! $this->admins->actorHas($user, PermissionSlug::AdminsSuspend)) {
            return false;
        }

        if ($user->is($admin)) {
            return false;
        }

        if ($admin->hasRole(RoleSlug::SuperAdmin) && ! $user->hasRole(RoleSlug::SuperAdmin)) {
            return false;
        }

        return $admin->hasAnyRole(...RoleSlug::appointableDeskRoles())
            && $admin->status === UserStatus::Active;
    }

    public function reinstate(User $user, User $admin): bool
    {
        if (! $this->admins->actorHas($user, PermissionSlug::AdminsSuspend)) {
            return false;
        }

        if ($admin->hasRole(RoleSlug::SuperAdmin) && ! $user->hasRole(RoleSlug::SuperAdmin)) {
            return false;
        }

        return $admin->hasAnyRole(...RoleSlug::appointableDeskRoles())
            && $admin->status === UserStatus::Suspended;
    }

    public function delete(User $user, User $admin): bool
    {
        if (! $this->admins->actorHas($user, PermissionSlug::AdminsDelete)) {
            return false;
        }

        if ($user->is($admin)) {
            return false;
        }

        if ($admin->hasRole(RoleSlug::SuperAdmin) && ! $user->hasRole(RoleSlug::SuperAdmin)) {
            return false;
        }

        return $admin->hasAnyRole(...RoleSlug::appointableDeskRoles());
    }

    public function resetPassword(User $user, User $admin): bool
    {
        return $this->update($user, $admin) && ! $user->is($admin);
    }
}
