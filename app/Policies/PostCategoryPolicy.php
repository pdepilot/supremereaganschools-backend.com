<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\PostCategory;
use App\Models\User;
use App\Services\PeopleAccessService;

class PostCategoryPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsView, PermissionSlug::NewsManage);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsManage);
    }

    public function update(User $user, PostCategory $category): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsManage);
    }

    public function delete(User $user, PostCategory $category): bool
    {
        return $this->access->allows($user, PermissionSlug::NewsManage);
    }
}
