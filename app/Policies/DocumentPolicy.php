<?php

namespace App\Policies;

use App\Enums\DocumentType;
use App\Models\AssignmentSubmission;
use App\Models\Document;
use App\Models\LearningMaterial;
use App\Models\User;
use App\Services\PeopleAccessService;

class DocumentPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function view(User $user, Document $document): bool
    {
        $document->loadMissing('documentable');

        if ($document->documentable instanceof LearningMaterial) {
            return $this->access->administers($user)
                || $this->access->canViewClassroomOffering($user, (int) $document->documentable->class_section_offering_id);
        }

        if ($document->type === DocumentType::LearningMaterial) {
            $material = LearningMaterial::query()->where('document_id', $document->id)->first();

            return $material !== null && (
                $this->access->administers($user)
                || $this->access->canViewClassroomOffering($user, (int) $material->class_section_offering_id)
            );
        }

        if ($document->documentable instanceof AssignmentSubmission) {
            return $this->canViewAssignmentSubmission($user, $document->documentable);
        }

        if ($document->type === DocumentType::AssignmentSubmission) {
            $submission = AssignmentSubmission::query()->where('document_id', $document->id)->first();

            return $submission !== null && $this->canViewAssignmentSubmission($user, $submission);
        }

        return $this->access->administers($user);
    }

    private function canViewAssignmentSubmission(User $user, AssignmentSubmission $submission): bool
    {
        if ($this->access->isStudent($user)) {
            return (int) $user->studentProfile?->id === (int) $submission->student_profile_id;
        }

        if ($this->access->isParent($user)) {
            return $this->access->linkedStudentIds($user)->contains((int) $submission->student_profile_id);
        }

        $submission->loadMissing('assignment');

        return $this->access->canViewClassroomOffering($user, (int) $submission->assignment?->class_section_offering_id);
    }
}
