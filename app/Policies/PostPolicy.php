<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use App\Services\PeopleAccessService;

class PostPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function view(User $user, Post $post): bool
    {
        return $this->access->administers($user);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function update(User $user, Post $post): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->access->administers($user);
    }

    public function publish(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function schedule(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function manageTaxonomy(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function manageSeo(User $user): bool
    {
        return $this->access->administers($user);
    }
}
