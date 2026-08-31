<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\ClassSectionOffering;
use App\Models\StudentProfile;
use App\Models\User;

class ClassroomService
{
    public function __construct(private readonly PeopleAccessService $access) {}

    /**
     * @return array<string, mixed>
     */
    public function context(User $user): array
    {
        $ids = $this->access->classroomOfferingIds($user);

        $offerings = ClassSectionOffering::query()
            ->with(['classSection', 'academicSession', 'subjectOfferings.subject', 'activeClassTeacher.staff.user'])
            ->whereIn('id', $ids->isEmpty() ? [0] : $ids)
            ->orderBy('id')
            ->get()
            ->map(function (ClassSectionOffering $offering) use ($user) {
                return [
                    'id' => $offering->id,
                    'form' => $offering->classSection?->name,
                    'session_name' => $offering->academicSession?->name,
                    'class_teacher' => $offering->activeClassTeacher->first()?->staff?->user?->name,
                    'can_edit_timetable' => $this->access->administers($user),
                    'can_post_work' => $this->access->canPostClassroomWork($user, $offering->id),
                    'subjects' => $offering->subjectOfferings
                        ->map(fn ($row) => [
                            'id' => $row->subject_id,
                            'name' => $row->subject?->name,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $children = [];
        if ($this->access->isParent($user) || $this->access->administers($user)) {
            $studentIds = $this->access->isParent($user)
                ? $this->access->linkedStudentIds($user)
                : collect();

            if ($studentIds->isNotEmpty()) {
                $children = StudentProfile::query()
                    ->with(['enrollments' => fn ($query) => $query
                        ->where('status', EnrollmentStatus::Active)
                        ->with('classSectionOffering.classSection')])
                    ->whereIn('id', $studentIds)
                    ->orderBy('surname')
                    ->get()
                    ->map(function (StudentProfile $student) {
                        $enrollment = $student->enrollments->first();

                        return [
                            'id' => $student->id,
                            'full_name' => $student->fullName(),
                            'class_section_offering_id' => $enrollment?->class_section_offering_id,
                            'form' => $enrollment?->classSectionOffering?->classSection?->name,
                        ];
                    })
                    ->values()
                    ->all();
            }
        }

        return [
            'offerings' => $offerings,
            'children' => $children,
            'can_compose_announcement' => $this->access->administers($user) || $this->access->isTeacher($user),
            'can_edit_timetable' => $this->access->administers($user),
        ];
    }
}
