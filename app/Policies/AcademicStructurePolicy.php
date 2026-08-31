<?php

namespace App\Policies;

use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\User;

class AcademicStructurePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->administers($user);
    }

    public function view(User $user, mixed $model = null): bool
    {
        return $this->administers($user);
    }

    public function create(User $user): bool
    {
        return $this->administers($user);
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $this->administers($user);
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $this->administers($user);
    }

    private function administers(User $user): bool
    {
        return $user->status === UserStatus::Active
            && $user->hasAnyRole(RoleSlug::SuperAdmin, RoleSlug::SchoolAdmin);
    }
}
