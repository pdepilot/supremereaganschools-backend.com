<?php

namespace App\Policies;

use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\PeopleAccessService;

class TimetableSlotPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user)
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
        return $this->access->administers($user);
    }

    public function update(User $user, TimetableSlot $slot): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, TimetableSlot $slot): bool
    {
        return $this->access->administers($user);
    }
}
