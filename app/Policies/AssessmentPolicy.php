<?php

namespace App\Policies;

use App\Models\AssessmentScore;
use App\Models\AssessmentType;
use App\Models\GradeScale;
use App\Models\TermResult;
use App\Models\User;
use App\Services\PeopleAccessService;

class AssessmentPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user)
            || $this->access->isTeacher($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, AssessmentScore $score): bool
    {
        $score->loadMissing('enrollment.student');
        $student = $score->enrollment?->student;

        if ($student !== null && $this->access->canViewStudent($user, $student)) {
            return true;
        }

        $offeringId = $score->enrollment?->class_section_offering_id;

        return $offeringId !== null && $this->access->canViewScoresForOffering($user, (int) $offeringId);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user) || $this->access->isTeacher($user);
    }

    public function update(User $user, AssessmentScore $score): bool
    {
        if (! $this->access->administers($user)) {
            return false;
        }

        $score->loadMissing('enrollment');
        $offeringId = $score->enrollment?->class_section_offering_id;

        return $offeringId !== null
            && $this->access->canEnterScoresFor($user, (int) $offeringId, (int) $score->subject_id);
    }

    public function viewResult(User $user, TermResult $result): bool
    {
        $result->loadMissing('enrollment.student');
        $student = $result->enrollment?->student;

        return $student !== null && $this->access->canViewStudent($user, $student);
    }

    public function viewCatalogue(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function viewType(User $user, AssessmentType $type): bool
    {
        return $this->viewCatalogue($user);
    }

    public function viewScale(User $user, GradeScale $scale): bool
    {
        return $this->viewCatalogue($user);
    }
}
