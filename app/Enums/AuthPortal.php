<?php

namespace App\Enums;

use App\Models\User;
use Illuminate\Http\Request;

enum AuthPortal: string
{
    case Portal = 'portal';
    case Staff = 'staff';
    case Parent = 'parent';
    case Student = 'student';

    /**
     * @return list<RoleSlug>
     */
    public function allowedRoles(): array
    {
        return match ($this) {
            self::Portal => [RoleSlug::SuperAdmin, RoleSlug::SchoolAdmin],
            self::Staff => [
                RoleSlug::Teacher,
                RoleSlug::Staff,
                RoleSlug::Principal,
                RoleSlug::VicePrincipal,
                RoleSlug::Accountant,
            ],
            self::Parent => [RoleSlug::Parent],
            self::Student => [RoleSlug::Student],
        };
    }

    public function homeRoute(): string
    {
        return match ($this) {
            self::Portal => 'portal.home',
            self::Staff => 'staff.home',
            self::Parent => 'parent.home',
            self::Student => 'student.home',
        };
    }

    public function loginRoute(): string
    {
        return match ($this) {
            self::Portal => 'login',
            self::Staff => 'staff.login',
            self::Parent => 'parent.login',
            self::Student => 'student.login',
        };
    }

    public function supportsEmailPassword(): bool
    {
        return $this !== self::Student;
    }

    public function passwordRequestRoute(): string
    {
        return match ($this) {
            self::Portal => 'password.request',
            self::Staff => 'staff.password.request',
            self::Parent => 'parent.password.request',
            self::Student => 'student.login',
        };
    }

    public function passwordResetRoute(): string
    {
        return match ($this) {
            self::Portal => 'password.reset',
            self::Staff => 'staff.password.reset',
            self::Parent => 'parent.password.reset',
            self::Student => 'student.login',
        };
    }

    public function deskName(): string
    {
        return match ($this) {
            self::Portal => 'the office',
            self::Staff => 'the staff desk',
            self::Parent => 'the family desk',
            self::Student => 'the pupil desk',
        };
    }

    public function admits(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(...$this->allowedRoles());
    }

    public static function forUser(?User $user): ?self
    {
        if ($user === null) {
            return null;
        }

        foreach (self::cases() as $portal) {
            if ($portal->admits($user)) {
                return $portal;
            }
        }

        return null;
    }

    public static function matchingRequest(Request $request): self
    {
        if ($request->is('staff') || $request->is('staff/*')) {
            return self::Staff;
        }

        if ($request->is('parent') || $request->is('parent/*')) {
            return self::Parent;
        }

        if ($request->is('student') || $request->is('student/*')) {
            return self::Student;
        }

        return self::Portal;
    }

    public static function fromLoginRequest(Request $request): ?self
    {
        if ($request->is('staff/login', 'staff/forgot-password', 'staff/reset-password', 'staff/reset-password/*')) {
            return self::Staff;
        }

        if ($request->is('parent/login', 'parent/forgot-password', 'parent/reset-password', 'parent/reset-password/*')) {
            return self::Parent;
        }

        if ($request->is('student/login')) {
            return self::Student;
        }

        if ($request->is('portal/login', 'portal/forgot-password', 'portal/reset-password', 'portal/reset-password/*') || $request->routeIs('login')) {
            return self::Portal;
        }

        if ($request->routeIs('login.store')) {
            $value = $request->input('portal', self::Portal->value);
            if ($value === 'admin') {
                $value = self::Portal->value;
            }

            return self::tryFrom((string) $value);
        }

        return null;
    }
}
