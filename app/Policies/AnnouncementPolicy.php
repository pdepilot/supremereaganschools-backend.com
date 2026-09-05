<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\Announcement;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\PeopleAccessService;

class AnnouncementPolicy
{
    public function __construct(
        private readonly PeopleAccessService $access,
        private readonly AnnouncementService $announcements,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::NoticesView, PermissionSlug::NoticesManage)
            || $this->access->isTeacher($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, Announcement $announcement): bool
    {
        return $this->announcements->canView($user, $announcement);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::NoticesManage)
            || $this->access->isTeacher($user);
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $this->access->allows($user, PermissionSlug::NoticesManage)
            || ((int) $announcement->created_by === (int) $user->id && $this->access->isTeacher($user));
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $this->update($user, $announcement);
    }
}
