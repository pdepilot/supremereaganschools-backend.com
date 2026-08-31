<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\PeopleAccessService;

class AttendanceRecordPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user)
            || $this->access->isTeacher($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, AttendanceRecord $record): bool
    {
        $record->loadMissing('enrollment.student');

        if ($this->access->administers($user)) {
            return true;
        }

        if ($this->access->canViewAttendanceForOffering($user, (int) $record->class_section_offering_id)) {
            return true;
        }

        $student = $record->enrollment?->student;

        return $student !== null && $this->access->canViewStudent($user, $student);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user) || $this->access->isTeacher($user);
    }

    public function update(User $user, AttendanceRecord $record): bool
    {
        return $this->access->canMarkAttendanceForOffering($user, (int) $record->class_section_offering_id);
    }

    public function delete(User $user, AttendanceRecord $record): bool
    {
        return $this->access->administers($user);
    }
}
