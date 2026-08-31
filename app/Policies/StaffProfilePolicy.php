<?php

namespace App\Policies;

use App\Models\StaffProfile;
use App\Models\User;
use App\Services\PeopleAccessService;

class StaffProfilePolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user) || $this->access->isTeacher($user);
    }

    public function view(User $user, StaffProfile $staff): bool
    {
        if ($this->access->administers($user)) {
            return true;
        }

        return $this->access->isTeacher($user) && $user->staffProfile?->id === $staff->id;
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function update(User $user, StaffProfile $staff): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, StaffProfile $staff): bool
    {
        return $this->access->administers($user);
    }
}
