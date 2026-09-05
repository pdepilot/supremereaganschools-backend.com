<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\PeopleAccessService;

class StudentProfilePolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::StudentsView)
            || $this->access->isTeacher($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, StudentProfile $student): bool
    {
        return $this->access->canViewStudent($user, $student);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::StudentsCreate);
    }

    public function update(User $user, StudentProfile $student): bool
    {
        return $this->access->allows($user, PermissionSlug::StudentsEdit);
    }

    public function delete(User $user, StudentProfile $student): bool
    {
        return $this->access->allows($user, PermissionSlug::StudentsDelete);
    }
}
