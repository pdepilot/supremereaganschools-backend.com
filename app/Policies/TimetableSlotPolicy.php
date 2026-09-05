<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\PeopleAccessService;

class TimetableSlotPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::TimetableView, PermissionSlug::TimetableManage)
            || $this->access->isTeacher($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, TimetableSlot $slot): bool
    {
        return $this->access->canViewClassroomOffering($user, (int) $slot->class_section_offering_id);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::TimetableManage);
    }

    public function update(User $user, TimetableSlot $slot): bool
    {
        return $this->access->allows($user, PermissionSlug::TimetableManage);
    }

    public function delete(User $user, TimetableSlot $slot): bool
    {
        return $this->access->allows($user, PermissionSlug::TimetableManage);
    }
}
