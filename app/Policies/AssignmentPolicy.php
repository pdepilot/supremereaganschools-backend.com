<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;
use App\Services\PeopleAccessService;

class AssignmentPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user)
            || $this->access->isTeacher($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, Assignment $assignment): bool
    {
        return $this->access->canViewClassroomOffering($user, (int) $assignment->class_section_offering_id);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user) || $this->access->isTeacher($user);
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return $this->access->canPostClassroomWork(
            $user,
            (int) $assignment->class_section_offering_id,
            (int) $assignment->subject_id,
        );
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return $this->update($user, $assignment);
    }

    public function submit(User $user, Assignment $assignment): bool
    {
        return $this->access->canSubmitAssignment($user, $assignment);
    }

    public function reviewSubmissions(User $user, Assignment $assignment): bool
    {
        return $this->access->canReviewAssignmentSubmissions($user, $assignment);
    }
}
