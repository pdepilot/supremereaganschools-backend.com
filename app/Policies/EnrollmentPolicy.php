<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\PeopleAccessService;

class EnrollmentPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::StudentsView)
            || $this->access->isTeacher($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, Enrollment $enrollment): bool
    {
        $enrollment->loadMissing('student');

        return $this->access->canViewEnrollment($user, $enrollment);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::StudentsCreate, PermissionSlug::StudentsEdit);
    }

    public function update(User $user, Enrollment $enrollment): bool
    {
        return $this->access->allows($user, PermissionSlug::StudentsEdit);
    }

    public function delete(User $user, Enrollment $enrollment): bool
    {
        return $this->access->allows($user, PermissionSlug::StudentsDelete, PermissionSlug::StudentsEdit);
    }
}
