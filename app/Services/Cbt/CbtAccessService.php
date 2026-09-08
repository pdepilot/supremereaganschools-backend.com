<?php

namespace App\Services\Cbt;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\StudentProfile;
use App\Models\User;

class CbtAccessService
{
    public function canEnter(User $user): bool
    {
        return $user->hasRole(RoleSlug::Student)
            || $user->hasAnyPermission(
                PermissionSlug::CbtView,
                PermissionSlug::CbtManage,
                PermissionSlug::CbtProctor,
                PermissionSlug::CbtMark,
            );
    }

    public function canManage(User $user): bool
    {
        return $user->hasPermission(PermissionSlug::CbtManage);
    }

    public function canMark(User $user): bool
    {
        return $user->hasPermission(PermissionSlug::CbtMark)
            || $this->canManage($user);
    }

    public function canProctor(User $user): bool
    {
        return $user->hasPermission(PermissionSlug::CbtProctor)
            || $this->canManage($user);
    }

    public function isStudentTaker(User $user): bool
    {
        return $user->hasRole(RoleSlug::Student) && $this->studentProfileFor($user) !== null;
    }

    public function studentProfileFor(User $user): ?StudentProfile
    {
        return StudentProfile::query()->where('user_id', $user->id)->first();
    }

    public function requireStudentProfile(User $user): StudentProfile
    {
        $profile = $this->studentProfileFor($user);

        abort_if($profile === null, 403);

        return $profile;
    }

    public function homePath(User $user): string
    {
        if ($this->isStudentTaker($user) && ! $this->canManage($user) && ! $this->canMark($user) && ! $this->canProctor($user)) {
            return route('cbt.home', absolute: false);
        }

        if ($this->canManage($user) || $this->canMark($user) || $this->canProctor($user)) {
            return route('cbt.admin.home', absolute: false);
        }

        if ($this->canEnter($user)) {
            return route('cbt.home', absolute: false);
        }

        return route('cbt.login', absolute: false);
    }
}
