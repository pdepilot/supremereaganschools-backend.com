<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\LearningMaterial;
use App\Models\SubjectOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialService
{
    public function __construct(
        private readonly PeopleAccessService $access,
        private readonly DocumentService $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, UploadedFile $file, User $actor): LearningMaterial
    {
        $offeringId = (int) $attributes['class_section_offering_id'];
        $subjectId = (int) $attributes['subject_id'];
        $this->assertMayPost($actor, $offeringId, $subjectId);
        $this->assertSubjectOffered($offeringId, $subjectId);

        $staffId = $actor->staffProfile?->id;
        if ($staffId === null) {
            throw ValidationException::withMessages([
                'staff_profile_id' => 'Only assigned teachers can upload materials.',
            ]);
        }

        return DB::transaction(function () use ($attributes, $file, $actor, $offeringId, $subjectId, $staffId) {
            $document = $this->documents->storeFile(
                $file,
                DocumentType::LearningMaterial,
                LearningMaterial::class,
                0,
                $actor,
            );

            $material = LearningMaterial::query()->create([
                'class_section_offering_id' => $offeringId,
                'subject_id' => $subjectId,
                'staff_profile_id' => $staffId,
                'title' => $attributes['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'document_id' => $document->id,
            ]);

            $document->update([
                'documentable_type' => LearningMaterial::class,
                'documentable_id' => $material->id,
            ]);

            return $material->load(['subject', 'staff.user', 'document', 'classSectionOffering.classSection']);
        });
    }

    public function delete(LearningMaterial $material, User $actor): void
    {
        $this->assertMayPost($actor, (int) $material->class_section_offering_id, (int) $material->subject_id);
        $material->delete();
    }

    public function visibleTo(User $user, ?int $offeringId = null, ?int $studentId = null)
    {
        $ids = $this->access->classroomOfferingIds($user);

        if ($studentId) {
            if ($this->access->isParent($user) && ! $this->access->linkedStudentIds($user)->contains($studentId)) {
                throw new AuthorizationException;
            }
            if ($this->access->isStudent($user) && (int) $user->studentProfile?->id !== $studentId) {
                throw new AuthorizationException;
            }

            $studentOfferings = \App\Models\Enrollment::query()
                ->where('student_profile_id', $studentId)
                ->where('status', \App\Enums\EnrollmentStatus::Active)
                ->pluck('class_section_offering_id');

            $ids = $ids->intersect($studentOfferings)->values();
        }

        if ($offeringId) {
            if (! $ids->contains($offeringId)) {
                throw new AuthorizationException;
            }
            $ids = collect([$offeringId]);
        }

        return LearningMaterial::query()
            ->with(['subject', 'staff.user', 'document', 'classSectionOffering.classSection'])
            ->whereIn('class_section_offering_id', $ids)
            ->orderByDesc('id');
    }

    public function canView(User $user, LearningMaterial $material): bool
    {
        return $this->access->canViewClassroomOffering($user, (int) $material->class_section_offering_id);
    }

    private function assertMayPost(User $actor, int $offeringId, int $subjectId): void
    {
        if (! $this->access->canPostClassroomWork($actor, $offeringId, $subjectId)) {
            throw new AuthorizationException;
        }
    }

    private function assertSubjectOffered(int $offeringId, int $subjectId): void
    {
        $exists = SubjectOffering::query()
            ->where('class_section_offering_id', $offeringId)
            ->where('subject_id', $subjectId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'subject_id' => 'This subject is not offered in the selected class.',
            ]);
        }
    }
}
