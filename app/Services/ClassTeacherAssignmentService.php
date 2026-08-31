<?php

namespace App\Services;

use App\Models\ClassSectionOffering;
use App\Models\ClassTeacherAssignment;
use App\Models\StaffProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassTeacherAssignmentService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assign(array $attributes, ?int $assignedBy = null): ClassTeacherAssignment
    {
        $staff = StaffProfile::query()->with('user.roles')->find($attributes['staff_profile_id']);
        $offering = ClassSectionOffering::query()->find($attributes['class_section_offering_id']);

        if ($staff === null) {
            throw ValidationException::withMessages([
                'staff_profile_id' => 'The selected staff member does not exist.',
            ]);
        }

        if (! $staff->isAssignableTeacher()) {
            throw ValidationException::withMessages([
                'staff_profile_id' => 'Only active teaching staff can be assigned as class teachers.',
            ]);
        }

        if ($offering === null) {
            throw ValidationException::withMessages([
                'class_section_offering_id' => 'The selected class offering does not exist.',
            ]);
        }

        $existing = ClassTeacherAssignment::query()
            ->where('staff_profile_id', $staff->id)
            ->where('class_section_offering_id', $offering->id)
            ->first();

        if ($existing?->is_active) {
            throw ValidationException::withMessages([
                'staff_profile_id' => 'This staff member is already the class teacher for that form.',
            ]);
        }

        return DB::transaction(function () use ($staff, $offering, $existing, $attributes, $assignedBy) {
            ClassTeacherAssignment::query()
                ->where('class_section_offering_id', $offering->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'ended_on' => now()->toDateString(),
                ]);

            if ($existing) {
                $existing->update([
                    'is_active' => true,
                    'assigned_on' => $attributes['assigned_on'] ?? now()->toDateString(),
                    'ended_on' => null,
                    'assigned_by' => $assignedBy,
                ]);

                return $existing->fresh(['staff.user', 'classSectionOffering.classSection']);
            }

            return ClassTeacherAssignment::query()->create([
                'staff_profile_id' => $staff->id,
                'class_section_offering_id' => $offering->id,
                'is_active' => true,
                'assigned_on' => $attributes['assigned_on'] ?? now()->toDateString(),
                'assigned_by' => $assignedBy,
            ])->load(['staff.user', 'classSectionOffering.classSection']);
        });
    }

    public function end(ClassTeacherAssignment $assignment): ClassTeacherAssignment
    {
        $assignment->update([
            'is_active' => false,
            'ended_on' => now()->toDateString(),
        ]);

        return $assignment->fresh(['staff.user', 'classSectionOffering.classSection']);
    }

    public function endActiveForStaff(StaffProfile $staff, ?int $exceptOfferingId = null): void
    {
        $query = $staff->classTeacherAssignments()->where('is_active', true);

        if ($exceptOfferingId !== null) {
            $query->where('class_section_offering_id', '!=', $exceptOfferingId);
        }

        $query->update([
            'is_active' => false,
            'ended_on' => now()->toDateString(),
        ]);
    }
}
