<?php

namespace App\Policies;

use App\Models\GuardianProfile;
use App\Models\User;
use App\Services\PeopleAccessService;

class GuardianProfilePolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function view(User $user, GuardianProfile $guardian): bool
    {
        if ($this->access->administers($user)) {
            return true;
        }

        return $this->access->isParent($user) && $user->guardianProfile?->id === $guardian->id;
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function update(User $user, GuardianProfile $guardian): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, GuardianProfile $guardian): bool
    {
        return $this->access->administers($user);
    }
}
