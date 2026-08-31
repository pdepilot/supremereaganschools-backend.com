<?php

namespace App\Policies;

use App\Models\LearningMaterial;
use App\Models\User;
use App\Services\PeopleAccessService;

class LearningMaterialPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user)
            || $this->access->isTeacher($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, LearningMaterial $material): bool
    {
        return $this->access->canViewClassroomOffering($user, (int) $material->class_section_offering_id);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user) || $this->access->isTeacher($user);
    }

    public function delete(User $user, LearningMaterial $material): bool
    {
        return $this->access->canPostClassroomWork(
            $user,
            (int) $material->class_section_offering_id,
            (int) $material->subject_id,
        );
    }
}
