<?php

namespace App\Policies;

use App\Models\GuardianStudent;
use App\Models\User;
use App\Services\PeopleAccessService;

class GuardianStudentPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, GuardianStudent $link): bool
    {
        return $this->access->administers($user);
    }
}
