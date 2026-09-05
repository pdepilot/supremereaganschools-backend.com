<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\User;
use App\Services\PeopleAccessService;

class AcademicStructurePolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::AcademicsView, PermissionSlug::AcademicsManage);
    }

    public function view(User $user, mixed $model = null): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::AcademicsManage);
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $this->access->allows($user, PermissionSlug::AcademicsManage);
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $this->access->allows($user, PermissionSlug::AcademicsManage);
    }
}
