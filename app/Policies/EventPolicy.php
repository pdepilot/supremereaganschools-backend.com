<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\Event;
use App\Models\User;
use App\Services\PeopleAccessService;

class EventPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::EventsView, PermissionSlug::EventsManage);
    }

    public function view(User $user, Event $event): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::EventsManage);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->access->allows($user, PermissionSlug::EventsManage);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->access->allows($user, PermissionSlug::EventsManage);
    }
}
