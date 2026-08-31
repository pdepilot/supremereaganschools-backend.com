<?php

namespace App\Policies;

use App\Models\PostTag;
use App\Models\User;
use App\Services\PeopleAccessService;

class PostTagPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function update(User $user, PostTag $tag): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, PostTag $tag): bool
    {
        return $this->access->administers($user);
    }
}
