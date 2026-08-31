<?php

namespace App\Services;

use App\Enums\RoleSlug;
use App\Enums\StaffStatus;
use App\Enums\UserStatus;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function __construct(
        private readonly SchoolNumberService $numbers,
        private readonly ClassTeacherAssignmentService $classTeachers,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?int $assignedBy = null): StaffProfile
    {
        $role = RoleSlug::from($attributes['role'] ?? RoleSlug::Teacher->value);

        $this->assertStaffRole($role);

        return DB::transaction(function () use ($attributes, $role, $assignedBy) {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'status' => UserStatus::Active,
            ]);
            $user->assignRole($role);

            $staff = StaffProfile::query()->create([
                'user_id' => $user->id,
                'staff_number' => $attributes['staff_number'] ?? $this->numbers->nextStaffNumber(),
                'department_id' => $attributes['department_id'] ?? null,
                'gender' => $attributes['gender'] ?? null,
                'job_title' => $attributes['job_title'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'employed_on' => $attributes['employed_on'] ?? null,
                'status' => $attributes['status'] ?? StaffStatus::Active->value,
            ]);

            $this->syncClassForm($staff, $attributes, $assignedBy);

            return $staff->fresh($this->defaultRelations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StaffProfile $staff, array $attributes, ?int $assignedBy = null): StaffProfile
    {
        return DB::transaction(function () use ($staff, $attributes, $assignedBy) {
            $userData = array_filter([
                'name' => $attributes['name'] ?? null,
                'email' => $attributes['email'] ?? null,
                'password' => $attributes['password'] ?? null,
            ], fn ($value) => $value !== null);

            if ($userData !== []) {
                $staff->user?->update($userData);
            }

            if (isset($attributes['role']) && $staff->user) {
                $role = RoleSlug::from($attributes['role']);
                $this->assertStaffRole($role);
                $staff->user->roles()->sync([
                    \App\Models\Role::query()->where('slug', $role->value)->firstOrFail()->id,
                ]);
                $staff->user->unsetRelation('roles');
            }

            unset(
                $attributes['name'],
                $attributes['email'],
                $attributes['password'],
                $attributes['role'],
            );

            $this->syncClassForm($staff, $attributes, $assignedBy);
            unset($attributes['class_section_offering_id']);

            $staff->update($attributes);

            return $staff->fresh($this->defaultRelations());
        });
    }

    public function delete(StaffProfile $staff): void
    {
        DB::transaction(function () use ($staff) {
            $endedOn = now()->toDateString();

            $staff->classTeacherAssignments()->where('is_active', true)->update([
                'is_active' => false,
                'ended_on' => $endedOn,
            ]);
            $staff->subjectTeacherAssignments()->where('is_active', true)->update([
                'is_active' => false,
                'ended_on' => $endedOn,
            ]);

            $staff->user?->update(['status' => UserStatus::Inactive]);
            $staff->update(['status' => StaffStatus::Inactive]);
            $staff->delete();
        });
    }

    public function suspend(StaffProfile $staff): StaffProfile
    {
        return DB::transaction(function () use ($staff) {
            $staff->user?->update(['status' => UserStatus::Suspended]);
            $staff->update(['status' => StaffStatus::Inactive]);

            return $staff->fresh($this->defaultRelations());
        });
    }

    public function reinstate(StaffProfile $staff): StaffProfile
    {
        return DB::transaction(function () use ($staff) {
            $staff->user?->update(['status' => UserStatus::Active]);
            $staff->update(['status' => StaffStatus::Active]);

            return $staff->fresh($this->defaultRelations());
        });
    }

    /**
     * @return array<int|string, mixed>
     */
    public function defaultRelations(): array
    {
        return [
            'user.roles',
            'department',
            'classTeacherAssignments' => fn ($query) => $query
                ->where('is_active', true)
                ->with('classSectionOffering.classSection'),
            'subjectTeacherAssignments' => fn ($query) => $query
                ->where('is_active', true)
                ->with('subjectOffering.subject'),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncClassForm(StaffProfile $staff, array $attributes, ?int $assignedBy): void
    {
        if (! array_key_exists('class_section_offering_id', $attributes)) {
            return;
        }

        $offeringId = $attributes['class_section_offering_id'];

        if ($offeringId === null || $offeringId === '') {
            $this->classTeachers->endActiveForStaff($staff);

            return;
        }

        $offeringId = (int) $offeringId;
        $this->classTeachers->endActiveForStaff($staff, $offeringId);

        $alreadyAssigned = $staff->classTeacherAssignments()
            ->where('class_section_offering_id', $offeringId)
            ->where('is_active', true)
            ->exists();

        if ($alreadyAssigned) {
            return;
        }

        $this->classTeachers->assign([
            'staff_profile_id' => $staff->id,
            'class_section_offering_id' => $offeringId,
        ], $assignedBy);
    }

    private function assertStaffRole(RoleSlug $role): void
    {
        $allowed = [
            RoleSlug::Teacher,
            RoleSlug::Staff,
            RoleSlug::Principal,
            RoleSlug::VicePrincipal,
            RoleSlug::Accountant,
        ];

        if (! in_array($role, $allowed, true)) {
            throw ValidationException::withMessages([
                'role' => 'Staff must be given a staff portal role.',
            ]);
        }
    }
}
