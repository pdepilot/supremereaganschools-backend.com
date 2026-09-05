<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\Assignment;
use App\Models\ClassTeacherAssignment;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\SubjectTeacherAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

class PeopleAccessService
{
    public function administers(User $user): bool
    {
        return $user->status === UserStatus::Active
            && (
                $user->hasRole(RoleSlug::SuperAdmin)
                || $user->hasPermission(PermissionSlug::DeskAdminister)
            );
    }

    public function allows(User $user, PermissionSlug|string ...$permissions): bool
    {
        if ($user->status !== UserStatus::Active) {
            return false;
        }

        if ($user->hasRole(RoleSlug::SuperAdmin) || $user->hasPermission(PermissionSlug::DeskAdminister)) {
            return true;
        }

        return $user->hasAnyPermission(...$permissions);
    }

    public function isTeacher(User $user): bool
    {
        return $user->status === UserStatus::Active
            && $user->hasAnyRole(
                RoleSlug::Teacher,
                RoleSlug::Staff,
            );
    }

    public function isParent(User $user): bool
    {
        return $user->status === UserStatus::Active && $user->hasRole(RoleSlug::Parent);
    }

    public function isStudent(User $user): bool
    {
        return $user->status === UserStatus::Active && $user->hasRole(RoleSlug::Student);
    }

    /**
     * @return Collection<int, int>
     */
    public function assignedOfferingIds(User $user): Collection
    {
        $staff = $user->staffProfile;

        if ($staff === null) {
            return collect();
        }

        $classOfferings = ClassTeacherAssignment::query()
            ->where('staff_profile_id', $staff->id)
            ->where('is_active', true)
            ->pluck('class_section_offering_id');

        $subjectOfferings = SubjectTeacherAssignment::query()
            ->where('staff_profile_id', $staff->id)
            ->where('is_active', true)
            ->with('subjectOffering:id,class_section_offering_id')
            ->get()
            ->pluck('subjectOffering.class_section_offering_id')
            ->filter();

        return $classOfferings->merge($subjectOfferings)->unique()->values();
    }

    /**
     * @return Collection<int, int>
     */
    public function assignedStudentIds(User $user): Collection
    {
        $offeringIds = $this->assignedOfferingIds($user);

        if ($offeringIds->isEmpty()) {
            return collect();
        }

        return Enrollment::query()
            ->whereIn('class_section_offering_id', $offeringIds)
            ->pluck('student_profile_id')
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    public function linkedStudentIds(User $user): Collection
    {
        $guardian = $user->guardianProfile;

        if ($guardian === null) {
            return collect();
        }

        return $guardian->students()->pluck('student_profiles.id');
    }

    public function canViewStudent(User $user, StudentProfile $student): bool
    {
        if ($this->allows($user, PermissionSlug::StudentsView)) {
            return true;
        }

        if ($this->isTeacher($user) && $this->assignedStudentIds($user)->contains($student->id)) {
            return true;
        }

        if ($this->isParent($user) && $this->linkedStudentIds($user)->contains($student->id)) {
            return true;
        }

        return $this->isStudent($user) && $user->studentProfile?->id === $student->id;
    }

    public function canViewEnrollment(User $user, Enrollment $enrollment): bool
    {
        return $this->canViewStudent($user, $enrollment->student);
    }

    /**
     * @return Collection<int, int>
     */
    public function classTeacherOfferingIds(User $user): Collection
    {
        $staff = $user->staffProfile;

        if ($staff === null) {
            return collect();
        }

        return ClassTeacherAssignment::query()
            ->where('staff_profile_id', $staff->id)
            ->where('is_active', true)
            ->pluck('class_section_offering_id');
    }

    public function canWriteClassTeacherComment(User $user, int $offeringId): bool
    {
        if ($this->administers($user)) {
            return true;
        }

        return $this->isTeacher($user) && $this->classTeacherOfferingIds($user)->contains($offeringId);
    }

    public function canWritePrincipalComment(User $user): bool
    {
        if ($this->administers($user)) {
            return true;
        }

        return $user->status === UserStatus::Active
            && $user->hasAnyRole(RoleSlug::Principal, RoleSlug::VicePrincipal);
    }

    public function canMarkAttendanceForOffering(User $user, int $offeringId): bool
    {
        if ($this->administers($user)) {
            return true;
        }

        return $this->isTeacher($user) && $this->classTeacherOfferingIds($user)->contains($offeringId);
    }

    public function canViewAttendanceForOffering(User $user, int $offeringId): bool
    {
        if ($this->administers($user)) {
            return true;
        }

        return $this->isTeacher($user) && $this->assignedOfferingIds($user)->contains($offeringId);
    }

    public function assignedSubjectIdsForOffering(User $user, int $offeringId): Collection
    {
        $staff = $user->staffProfile;

        if ($staff === null) {
            return collect();
        }

        return SubjectTeacherAssignment::query()
            ->where('staff_profile_id', $staff->id)
            ->where('is_active', true)
            ->whereHas('subjectOffering', fn ($query) => $query->where('class_section_offering_id', $offeringId))
            ->with('subjectOffering:id,subject_id')
            ->get()
            ->pluck('subjectOffering.subject_id')
            ->filter()
            ->unique()
            ->values();
    }

    public function canEnterScoresFor(User $user, int $offeringId, int $subjectId): bool
    {
        if ($this->administers($user)) {
            return true;
        }

        if (! $this->isTeacher($user)) {
            return false;
        }

        if ($this->classTeacherOfferingIds($user)->contains($offeringId)) {
            return true;
        }

        return $this->assignedSubjectIdsForOffering($user, $offeringId)->contains($subjectId);
    }

    public function canViewScoresForOffering(User $user, int $offeringId): bool
    {
        if ($this->administers($user)) {
            return true;
        }

        return $this->isTeacher($user) && $this->assignedOfferingIds($user)->contains($offeringId);
    }

    /**
     * @return Collection<int, int>
     */
    public function classroomOfferingIds(User $user): Collection
    {
        if ($this->administers($user)) {
            return \App\Models\ClassSectionOffering::query()->pluck('id');
        }

        if ($this->isTeacher($user)) {
            return $this->assignedOfferingIds($user);
        }

        if ($this->isStudent($user)) {
            return Enrollment::query()
                ->where('student_profile_id', $user->studentProfile?->id)
                ->where('status', EnrollmentStatus::Active)
                ->pluck('class_section_offering_id');
        }

        if ($this->isParent($user)) {
            $ids = $this->linkedStudentIds($user);

            return Enrollment::query()
                ->whereIn('student_profile_id', $ids)
                ->where('status', EnrollmentStatus::Active)
                ->pluck('class_section_offering_id')
                ->unique()
                ->values();
        }

        return collect();
    }

    public function canViewClassroomOffering(User $user, int $offeringId): bool
    {
        return $this->classroomOfferingIds($user)->contains($offeringId);
    }

    public function canPostClassroomWork(User $user, int $offeringId, ?int $subjectId = null): bool
    {
        if ($this->administers($user)) {
            return true;
        }

        if (! $this->isTeacher($user) || $user->staffProfile === null) {
            return false;
        }

        if ($this->classTeacherOfferingIds($user)->contains($offeringId)) {
            return $subjectId === null || $this->subjectOfferedOn($offeringId, $subjectId);
        }

        return $subjectId !== null && $this->assignedSubjectIdsForOffering($user, $offeringId)->contains($subjectId);
    }

    public function canSubmitAssignment(User $user, Assignment $assignment): bool
    {
        if (! $this->isStudent($user) || $user->studentProfile === null) {
            return false;
        }

        return Enrollment::query()
            ->where('student_profile_id', $user->studentProfile->id)
            ->where('class_section_offering_id', $assignment->class_section_offering_id)
            ->where('status', EnrollmentStatus::Active)
            ->exists();
    }

    public function canReviewAssignmentSubmissions(User $user, Assignment $assignment): bool
    {
        if ($this->administers($user)) {
            return true;
        }

        return $this->isTeacher($user)
            && $this->canViewClassroomOffering($user, (int) $assignment->class_section_offering_id);
    }

    public function canMessage(User $actor, User $target): bool
    {
        if ($actor->id === $target->id || $target->status !== UserStatus::Active) {
            return false;
        }

        if ($this->administers($actor) || $this->administers($target)) {
            return true;
        }

        if ($this->isTeacher($actor) && $this->isTeacher($target)) {
            return true;
        }

        if ($this->isTeacher($actor) && $this->isParent($target)) {
            return $this->assignedStudentIds($actor)->intersect($this->linkedStudentIds($target))->isNotEmpty();
        }

        if ($this->isTeacher($actor) && $this->isStudent($target)) {
            return $this->assignedStudentIds($actor)->contains($target->studentProfile?->id);
        }

        if ($this->isParent($actor) && $this->isTeacher($target)) {
            return $this->canMessage($target, $actor);
        }

        if ($this->isStudent($actor) && $this->isTeacher($target)) {
            return $this->canMessage($target, $actor);
        }

        return false;
    }

    public function isInSecondary(User $user): bool
    {
        $offeringIds = $this->classroomOfferingIds($user);

        if ($offeringIds->isEmpty()) {
            return false;
        }

        return \App\Models\ClassSectionOffering::query()
            ->whereIn('id', $offeringIds)
            ->whereHas('classSection.schoolClass.level', fn ($query) => $query->whereIn('slug', ['jss', 'ss']))
            ->exists();
    }

    private function subjectOfferedOn(int $offeringId, int $subjectId): bool
    {
        return \App\Models\SubjectOffering::query()
            ->where('class_section_offering_id', $offeringId)
            ->where('subject_id', $subjectId)
            ->exists();
    }
}
