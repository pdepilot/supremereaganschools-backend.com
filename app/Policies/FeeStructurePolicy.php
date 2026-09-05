<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\FeeStructure;
use App\Models\User;
use App\Services\PeopleAccessService;

class FeeStructurePolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::FeesView, PermissionSlug::FeesManage);
    }

    public function view(User $user, FeeStructure $structure): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::FeesManage);
    }

    public function update(User $user, FeeStructure $structure): bool
    {
        return $this->access->allows($user, PermissionSlug::FeesManage);
    }

    public function delete(User $user, FeeStructure $structure): bool
    {
        return $this->access->allows($user, PermissionSlug::FeesManage);
    }
}
