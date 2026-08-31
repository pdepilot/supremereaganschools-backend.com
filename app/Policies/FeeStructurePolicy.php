<?php

namespace App\Policies;

use App\Models\FeeStructure;
use App\Models\User;
use App\Services\PeopleAccessService;

class FeeStructurePolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function view(User $user, FeeStructure $structure): bool
    {
        return $this->access->administers($user);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function update(User $user, FeeStructure $structure): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, FeeStructure $structure): bool
    {
        return $this->access->administers($user);
    }
}
