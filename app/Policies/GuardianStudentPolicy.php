<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\GuardianStudent;
use App\Models\User;
use App\Services\PeopleAccessService;

class GuardianStudentPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::GuardiansCreate, PermissionSlug::GuardiansEdit, PermissionSlug::StudentsEdit);
    }

    public function delete(User $user, GuardianStudent $link): bool
    {
        return $this->access->allows($user, PermissionSlug::GuardiansDelete, PermissionSlug::GuardiansEdit, PermissionSlug::StudentsEdit);
    }
}
