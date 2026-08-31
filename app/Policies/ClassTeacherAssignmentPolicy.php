<?php

namespace App\Policies;

use App\Models\ClassTeacherAssignment;
use App\Models\User;
use App\Services\PeopleAccessService;

class ClassTeacherAssignmentPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user) || $this->access->isTeacher($user);
    }

    public function view(User $user, ClassTeacherAssignment $assignment): bool
    {
        if ($this->access->administers($user)) {
            return true;
        }

        return $this->access->isTeacher($user)
            && $user->staffProfile?->id === $assignment->staff_profile_id;
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function update(User $user, ClassTeacherAssignment $assignment): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, ClassTeacherAssignment $assignment): bool
    {
        return $this->access->administers($user);
    }
}
