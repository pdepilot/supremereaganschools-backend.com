<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\Post;
use App\Models\User;
use App\Services\PeopleAccessService;

class PostPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsView, PermissionSlug::NewsManage);
    }

    public function view(User $user, Post $post): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsManage);
    }

    public function update(User $user, Post $post): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsManage);
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsManage);
    }

    public function publish(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsManage);
    }

    public function schedule(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsManage);
    }

    public function manageTaxonomy(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsManage);
    }

    public function manageSeo(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsManage);
    }
}
