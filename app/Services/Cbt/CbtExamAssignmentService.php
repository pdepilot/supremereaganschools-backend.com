<?php

namespace App\Services\Cbt;

use App\Enums\EnrollmentStatus;
use App\Models\CbtExam;
use App\Models\CbtExamAssignment;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CbtExamAssignmentService
{
    public function assignToOffering(CbtExam $exam, int $classSectionOfferingId, ?int $assignedBy = null): CbtExamAssignment
    {
        return $this->createAssignment($exam, [
            'class_section_offering_id' => $classSectionOfferingId,
            'student_profile_id' => null,
            'assigned_by' => $assignedBy,
        ]);
    }

    public function assignToStudent(CbtExam $exam, int $studentProfileId, ?int $assignedBy = null): CbtExamAssignment
    {
        return $this->createAssignment($exam, [
            'class_section_offering_id' => null,
            'student_profile_id' => $studentProfileId,
            'assigned_by' => $assignedBy,
        ]);
    }

    /**
     * @param  array{class_section_offering_id: ?int, student_profile_id: ?int, assigned_by: ?int}  $attributes
     */
    private function createAssignment(CbtExam $exam, array $attributes): CbtExamAssignment
    {
        $hasOffering = $attributes['class_section_offering_id'] !== null;
        $hasStudent = $attributes['student_profile_id'] !== null;

        if ($hasOffering === $hasStudent) {
            throw ValidationException::withMessages([
                'assignment' => 'Assign exactly one of class section offering or student.',
            ]);
        }

        return DB::transaction(function () use ($exam, $attributes, $hasOffering) {
            $query = CbtExamAssignment::query()->where('exam_id', $exam->id);

            if ($hasOffering) {
                $query->where('class_section_offering_id', $attributes['class_section_offering_id']);
            } else {
                $query->where('student_profile_id', $attributes['student_profile_id']);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'assignment' => 'This exam assignment already exists.',
                ]);
            }

            return CbtExamAssignment::query()->create([
                'exam_id' => $exam->id,
                'class_section_offering_id' => $attributes['class_section_offering_id'],
                'student_profile_id' => $attributes['student_profile_id'],
                'assigned_by' => $attributes['assigned_by'],
            ]);
        });
    }

    public function remove(CbtExamAssignment $assignment): void
    {
        $assignment->delete();
    }

    public function isStudentEligible(CbtExam $exam, StudentProfile $student): bool
    {
        $exam->loadMissing('assignments');

        if ($exam->assignments->contains(fn (CbtExamAssignment $row) => (int) $row->student_profile_id === (int) $student->id)) {
            return true;
        }

        $offeringIds = $exam->assignments
            ->pluck('class_section_offering_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($offeringIds === []) {
            return false;
        }

        return Enrollment::query()
            ->where('student_profile_id', $student->id)
            ->where('status', EnrollmentStatus::Active)
            ->whereIn('class_section_offering_id', $offeringIds)
            ->exists();
    }

    public function assertStudentEligible(CbtExam $exam, StudentProfile $student): void
    {
        if (! $this->isStudentEligible($exam, $student)) {
            throw ValidationException::withMessages([
                'exam' => 'Student is not assigned to this exam.',
            ]);
        }
    }

    public function activeEnrollmentForExam(CbtExam $exam, StudentProfile $student): ?Enrollment
    {
        $exam->loadMissing('assignments');

        $offeringIds = $exam->assignments
            ->pluck('class_section_offering_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($offeringIds === []) {
            // Individual assignment: use active enrollment on exam's offering when present
            return Enrollment::query()
                ->where('student_profile_id', $student->id)
                ->where('status', EnrollmentStatus::Active)
                ->where('class_section_offering_id', $exam->class_section_offering_id)
                ->first()
                ?? Enrollment::query()
                    ->where('student_profile_id', $student->id)
                    ->where('status', EnrollmentStatus::Active)
                    ->orderByDesc('id')
                    ->first();
        }

        return Enrollment::query()
            ->where('student_profile_id', $student->id)
            ->where('status', EnrollmentStatus::Active)
            ->whereIn('class_section_offering_id', $offeringIds)
            ->orderByDesc('id')
            ->first();
    }
}
