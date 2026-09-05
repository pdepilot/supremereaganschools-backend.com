<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\SubjectTeacherAssignment;
use App\Models\User;
use App\Services\PeopleAccessService;

class SubjectTeacherAssignmentPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::StaffView, PermissionSlug::AcademicsView)
            || $this->access->isTeacher($user);
    }

    public function view(User $user, SubjectTeacherAssignment $assignment): bool
    {
        if ($this->access->allows($user, PermissionSlug::StaffView, PermissionSlug::AcademicsView)) {
            return true;
        }

        return $this->access->isTeacher($user)
            && $user->staffProfile?->id === $assignment->staff_profile_id;
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::StaffEdit, PermissionSlug::AcademicsManage);
    }

    public function update(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $this->access->allows($user, PermissionSlug::StaffEdit, PermissionSlug::AcademicsManage);
    }

    public function delete(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $this->access->allows($user, PermissionSlug::StaffEdit, PermissionSlug::AcademicsManage);
    }
}
