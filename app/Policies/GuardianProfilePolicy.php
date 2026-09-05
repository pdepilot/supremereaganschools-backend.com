<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\GuardianProfile;
use App\Models\User;
use App\Services\PeopleAccessService;

class GuardianProfilePolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::GuardiansView);
    }

    public function view(User $user, GuardianProfile $guardian): bool
    {
        if ($this->access->allows($user, PermissionSlug::GuardiansView)) {
            return true;
        }

        return $this->access->isParent($user) && $user->guardianProfile?->id === $guardian->id;
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::GuardiansCreate);
    }

    public function update(User $user, GuardianProfile $guardian): bool
    {
        return $this->access->allows($user, PermissionSlug::GuardiansEdit);
    }

    public function delete(User $user, GuardianProfile $guardian): bool
    {
        return $this->access->allows($user, PermissionSlug::GuardiansDelete);
    }
}
