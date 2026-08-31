<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\ClassSection;
use App\Models\ClassSectionOffering;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?int $createdBy = null): Enrollment
    {
        $student = StudentProfile::query()->findOrFail($attributes['student_profile_id']);
        $offering = $this->resolveOffering($attributes);

        $this->assertDates($attributes['enrolled_on'] ?? now()->toDateString(), $attributes['left_on'] ?? null);
        $this->assertNoDuplicateSession($student, $offering->academic_session_id);

        $status = EnrollmentStatus::from($attributes['status'] ?? EnrollmentStatus::Active->value);

        return DB::transaction(function () use ($student, $offering, $attributes, $status, $createdBy) {
            if ($status === EnrollmentStatus::Active) {
                $this->completeOtherActive($student);
            }

            return Enrollment::query()->create([
                'student_profile_id' => $student->id,
                'class_section_offering_id' => $offering->id,
                'academic_session_id' => $offering->academic_session_id,
                'status' => $status,
                'enrolled_on' => $attributes['enrolled_on'] ?? now()->toDateString(),
                'left_on' => $attributes['left_on'] ?? null,
                'created_by' => $createdBy,
            ])->load([
                'student',
                'academicSession',
                'classSectionOffering.classSection.schoolClass.level',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Enrollment $enrollment, array $attributes): Enrollment
    {
        $starts = $attributes['enrolled_on'] ?? $enrollment->enrolled_on?->toDateString();
        $ends = array_key_exists('left_on', $attributes) ? $attributes['left_on'] : $enrollment->left_on?->toDateString();
        $this->assertDates($starts, $ends);

        if (isset($attributes['class_section_id']) || isset($attributes['academic_session_id']) || isset($attributes['class_section_offering_id'])) {
            $offering = $this->resolveOffering([
                'class_section_offering_id' => $attributes['class_section_offering_id'] ?? $enrollment->class_section_offering_id,
                'class_section_id' => $attributes['class_section_id'] ?? $enrollment->classSectionOffering->class_section_id,
                'academic_session_id' => $attributes['academic_session_id'] ?? $enrollment->academic_session_id,
                'school_class_id' => $attributes['school_class_id'] ?? null,
            ]);

            if ((int) $offering->academic_session_id !== (int) $enrollment->academic_session_id) {
                $this->assertNoDuplicateSession($enrollment->student, $offering->academic_session_id, $enrollment->id);
            }

            $attributes['class_section_offering_id'] = $offering->id;
            $attributes['academic_session_id'] = $offering->academic_session_id;
        }

        unset($attributes['class_section_id'], $attributes['school_class_id']);

        $enrollment->update($attributes);

        return $enrollment->fresh([
            'student',
            'academicSession',
            'classSectionOffering.classSection.schoolClass.level',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveOffering(array $attributes): ClassSectionOffering
    {
        if (! empty($attributes['class_section_offering_id'])) {
            $offering = ClassSectionOffering::query()->with('classSection')->find($attributes['class_section_offering_id']);

            if ($offering === null) {
                throw ValidationException::withMessages([
                    'class_section_offering_id' => 'The selected class offering does not exist.',
                ]);
            }

            return $offering;
        }

        $sectionId = $attributes['class_section_id'] ?? null;
        $sessionId = $attributes['academic_session_id'] ?? null;

        if (! $sectionId || ! $sessionId) {
            throw ValidationException::withMessages([
                'class_section_id' => 'A class arm and academic session are required.',
            ]);
        }

        $section = ClassSection::query()->find($sectionId);

        if ($section === null) {
            throw ValidationException::withMessages([
                'class_section_id' => 'The selected arm does not exist.',
            ]);
        }

        if (! empty($attributes['school_class_id']) && (int) $section->school_class_id !== (int) $attributes['school_class_id']) {
            throw ValidationException::withMessages([
                'class_section_id' => 'The selected arm does not belong to that class.',
            ]);
        }

        $offering = ClassSectionOffering::query()
            ->where('class_section_id', $section->id)
            ->where('academic_session_id', $sessionId)
            ->first();

        if ($offering === null) {
            throw ValidationException::withMessages([
                'class_section_id' => 'This arm is not offered in the selected academic session.',
            ]);
        }

        return $offering;
    }

    private function assertNoDuplicateSession(StudentProfile $student, int $sessionId, ?int $ignoreId = null): void
    {
        $exists = Enrollment::query()
            ->where('student_profile_id', $student->id)
            ->where('academic_session_id', $sessionId)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'academic_session_id' => 'This pupil already has an enrollment for that academic session.',
            ]);
        }
    }

    private function completeOtherActive(StudentProfile $student): void
    {
        Enrollment::query()
            ->where('student_profile_id', $student->id)
            ->where('status', EnrollmentStatus::Active)
            ->update([
                'status' => EnrollmentStatus::Completed,
                'left_on' => now()->toDateString(),
            ]);
    }

    private function assertDates(mixed $startsOn, mixed $endsOn): void
    {
        if ($endsOn !== null && $startsOn !== null && strtotime((string) $endsOn) < strtotime((string) $startsOn)) {
            throw ValidationException::withMessages([
                'left_on' => 'The leaving date cannot precede the enrollment date.',
            ]);
        }
    }
}
