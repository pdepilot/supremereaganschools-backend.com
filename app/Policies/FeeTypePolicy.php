<?php

namespace App\Policies;

use App\Models\FeeType;
use App\Models\User;
use App\Services\PeopleAccessService;

class FeeTypePolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function view(User $user, FeeType $type): bool
    {
        return $this->access->administers($user);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function update(User $user, FeeType $type): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, FeeType $type): bool
    {
        return $this->access->administers($user);
    }
}
