<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\AssessmentScore;
use App\Models\ClassSectionOffering;
use App\Models\ClassTeacherAssignment;
use App\Models\Enrollment;
use App\Models\SubjectOffering;
use App\Models\SubjectTeacherAssignment;
use App\Models\TermResult;
use App\Models\TermSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicSessionPromotionService
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function promote(AcademicSession $target, AcademicSession $source, array $options = [], ?int $promotedBy = null): array
    {
        if ((int) $target->id === (int) $source->id) {
            throw ValidationException::withMessages([
                'source_academic_session_id' => 'Choose a different year to copy from.',
            ]);
        }

        if ($target->status === SessionStatus::Archived) {
            throw ValidationException::withMessages([
                'academic_session' => 'An archived year cannot receive forms. Re-open it first.',
            ]);
        }

        $copyTeachers = (bool) ($options['copy_teachers'] ?? true);
        $enrollPupils = (bool) ($options['enroll_pupils'] ?? true);
        $onlyActive = (bool) ($options['only_active_offerings'] ?? true);

        return DB::transaction(function () use ($target, $source, $copyTeachers, $enrollPupils, $onlyActive, $promotedBy) {
            $sourceOfferings = ClassSectionOffering::query()
                ->with([
                    'subjectOfferings.teacherAssignments' => fn ($query) => $query->where('is_active', true),
                    'classTeacherAssignments' => fn ($query) => $query->where('is_active', true),
                ])
                ->where('academic_session_id', $source->id)
                ->when($onlyActive, fn ($query) => $query->where('is_active', true))
                ->orderBy('id')
                ->get();

            $stats = [
                'source_academic_session_id' => $source->id,
                'source_name' => $source->name,
                'target_academic_session_id' => $target->id,
                'target_name' => $target->name,
                'offerings_created' => 0,
                'offerings_existing' => 0,
                'subjects_copied' => 0,
                'class_teachers_copied' => 0,
                'subject_teachers_copied' => 0,
                'enrollments_created' => 0,
                'enrollments_skipped' => 0,
                'marks_moved' => 0,
            ];

            $offeringMap = [];
            $subjectOfferingMap = [];

            foreach ($sourceOfferings as $old) {
                $existing = ClassSectionOffering::query()
                    ->where('class_section_id', $old->class_section_id)
                    ->where('academic_session_id', $target->id)
                    ->first();

                $new = ClassSectionOffering::query()->updateOrCreate(
                    [
                        'class_section_id' => $old->class_section_id,
                        'academic_session_id' => $target->id,
                    ],
                    [
                        'campus_id' => $old->campus_id,
                        'capacity' => $old->capacity,
                        'is_active' => true,
                    ],
                );

                $offeringMap[$old->id] = $new;
                $existing ? $stats['offerings_existing']++ : $stats['offerings_created']++;

                foreach ($old->subjectOfferings as $oldSubject) {
                    $subject = SubjectOffering::query()->firstOrCreate([
                        'class_section_offering_id' => $new->id,
                        'subject_id' => $oldSubject->subject_id,
                    ]);
                    $subjectOfferingMap[$oldSubject->id] = $subject;
                    $stats['subjects_copied']++;
                }

                if (! $copyTeachers) {
                    continue;
                }

                $hasClassTeacher = ClassTeacherAssignment::query()
                    ->where('class_section_offering_id', $new->id)
                    ->where('is_active', true)
                    ->exists();

                if (! $hasClassTeacher) {
                    $from = $old->classTeacherAssignments->first();
                    if ($from) {
                        ClassTeacherAssignment::query()->updateOrCreate(
                            [
                                'staff_profile_id' => $from->staff_profile_id,
                                'class_section_offering_id' => $new->id,
                            ],
                            [
                                'is_active' => true,
                                'assigned_on' => $target->starts_on?->toDateString() ?? now()->toDateString(),
                                'ended_on' => null,
                                'assigned_by' => $promotedBy,
                            ],
                        );
                        $stats['class_teachers_copied']++;
                    }
                }

                foreach ($old->subjectOfferings as $oldSubject) {
                    $newSubject = $subjectOfferingMap[$oldSubject->id] ?? null;
                    if ($newSubject === null) {
                        continue;
                    }

                    foreach ($oldSubject->teacherAssignments as $assignment) {
                        $already = SubjectTeacherAssignment::query()
                            ->where('staff_profile_id', $assignment->staff_profile_id)
                            ->where('subject_offering_id', $newSubject->id)
                            ->where('is_active', true)
                            ->exists();

                        if ($already) {
                            continue;
                        }

                        SubjectTeacherAssignment::query()->updateOrCreate(
                            [
                                'staff_profile_id' => $assignment->staff_profile_id,
                                'subject_offering_id' => $newSubject->id,
                            ],
                            [
                                'is_active' => true,
                                'assigned_on' => $target->starts_on?->toDateString() ?? now()->toDateString(),
                                'ended_on' => null,
                                'assigned_by' => $promotedBy,
                            ],
                        );
                        $stats['subject_teachers_copied']++;
                    }
                }
            }

            if ($enrollPupils && $offeringMap !== []) {
                $targetTermIds = $target->terms()->pluck('id');
                $oldIds = array_keys($offeringMap);

                $pupils = Enrollment::query()
                    ->where('academic_session_id', $source->id)
                    ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
                    ->whereIn('class_section_offering_id', $oldIds)
                    ->orderBy('id')
                    ->get();

                foreach ($pupils as $oldEnrollment) {
                    $newOffering = $offeringMap[$oldEnrollment->class_section_offering_id] ?? null;
                    if ($newOffering === null) {
                        continue;
                    }

                    $already = Enrollment::query()
                        ->where('student_profile_id', $oldEnrollment->student_profile_id)
                        ->where('academic_session_id', $target->id)
                        ->first();

                    if ($already !== null) {
                        $stats['enrollments_skipped']++;
                        $stats['marks_moved'] += $this->adoptLiveMarks($oldEnrollment, $already, $targetTermIds);

                        continue;
                    }

                    $created = $this->enrollments->create([
                        'student_profile_id' => $oldEnrollment->student_profile_id,
                        'class_section_offering_id' => $newOffering->id,
                        'status' => EnrollmentStatus::Active->value,
                        'enrolled_on' => $target->starts_on?->toDateString() ?? now()->toDateString(),
                    ], $promotedBy);

                    $stats['enrollments_created']++;
                    $stats['marks_moved'] += $this->adoptLiveMarks($oldEnrollment, $created, $targetTermIds);
                }
            }

            return $stats;
        });
    }

    /**
     * @param  Collection<int, int|string>  $targetTermIds
     */
    private function adoptLiveMarks(Enrollment $from, Enrollment $to, Collection $targetTermIds): int
    {
        if ($targetTermIds->isEmpty() || (int) $from->id === (int) $to->id) {
            return 0;
        }

        $moved = 0;

        AssessmentScore::query()
            ->where('enrollment_id', $from->id)
            ->whereIn('term_id', $targetTermIds)
            ->get()
            ->each(function (AssessmentScore $score) use ($to, &$moved) {
                $exists = AssessmentScore::query()
                    ->where('enrollment_id', $to->id)
                    ->where('term_id', $score->term_id)
                    ->where('subject_id', $score->subject_id)
                    ->where('assessment_type_id', $score->assessment_type_id)
                    ->exists();

                if ($exists) {
                    return;
                }

                $score->update(['enrollment_id' => $to->id]);
                $moved++;
            });

        TermResult::query()
            ->where('enrollment_id', $from->id)
            ->whereIn('term_id', $targetTermIds)
            ->get()
            ->each(function (TermResult $row) use ($to) {
                $exists = TermResult::query()
                    ->where('enrollment_id', $to->id)
                    ->where('term_id', $row->term_id)
                    ->where('subject_id', $row->subject_id)
                    ->exists();

                if ($exists) {
                    return;
                }

                $row->update(['enrollment_id' => $to->id]);
            });

        TermSummary::query()
            ->where('enrollment_id', $from->id)
            ->whereIn('term_id', $targetTermIds)
            ->get()
            ->each(function (TermSummary $row) use ($to) {
                $exists = TermSummary::query()
                    ->where('enrollment_id', $to->id)
                    ->where('term_id', $row->term_id)
                    ->exists();

                if ($exists) {
                    return;
                }

                $row->update(['enrollment_id' => $to->id]);
            });

        return $moved;
    }
}
