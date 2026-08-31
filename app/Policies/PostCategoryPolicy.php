<?php

namespace App\Policies;

use App\Models\PostCategory;
use App\Models\User;
use App\Services\PeopleAccessService;

class PostCategoryPolicy
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

    public function update(User $user, PostCategory $category): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, PostCategory $category): bool
    {
        return $this->access->administers($user);
    }
}
