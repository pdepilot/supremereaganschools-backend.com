<?php

namespace App\Services;

use App\Models\StaffProfile;
use App\Models\SubjectOffering;
use App\Models\SubjectTeacherAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectTeacherAssignmentService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assign(array $attributes, ?int $assignedBy = null): SubjectTeacherAssignment
    {
        $staff = StaffProfile::query()->with('user.roles')->find($attributes['staff_profile_id']);
        $offering = SubjectOffering::query()->find($attributes['subject_offering_id']);

        if ($staff === null) {
            throw ValidationException::withMessages([
                'staff_profile_id' => 'The selected staff member does not exist.',
            ]);
        }

        if (! $staff->isAssignableTeacher()) {
            throw ValidationException::withMessages([
                'staff_profile_id' => 'Only active teaching staff can be assigned to subjects.',
            ]);
        }

        if ($offering === null) {
            throw ValidationException::withMessages([
                'subject_offering_id' => 'The selected subject offering does not exist.',
            ]);
        }

        $existing = SubjectTeacherAssignment::query()
            ->where('staff_profile_id', $staff->id)
            ->where('subject_offering_id', $offering->id)
            ->first();

        if ($existing?->is_active) {
            throw ValidationException::withMessages([
                'staff_profile_id' => 'This staff member is already assigned to that subject offering.',
            ]);
        }

        return DB::transaction(function () use ($staff, $offering, $existing, $attributes, $assignedBy) {
            if ($existing) {
                $existing->update([
                    'is_active' => true,
                    'assigned_on' => $attributes['assigned_on'] ?? now()->toDateString(),
                    'ended_on' => null,
                    'assigned_by' => $assignedBy,
                ]);

                return $existing->fresh(['staff.user', 'subjectOffering.subject']);
            }

            return SubjectTeacherAssignment::query()->create([
                'staff_profile_id' => $staff->id,
                'subject_offering_id' => $offering->id,
                'is_active' => true,
                'assigned_on' => $attributes['assigned_on'] ?? now()->toDateString(),
                'assigned_by' => $assignedBy,
            ])->load(['staff.user', 'subjectOffering.subject']);
        });
    }

    public function end(SubjectTeacherAssignment $assignment): SubjectTeacherAssignment
    {
        $assignment->update([
            'is_active' => false,
            'ended_on' => now()->toDateString(),
        ]);

        return $assignment->fresh(['staff.user', 'subjectOffering.subject']);
    }
}
