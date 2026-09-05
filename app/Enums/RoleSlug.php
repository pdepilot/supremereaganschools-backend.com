<?php

namespace App\Enums;

enum RoleSlug: string
{
    case SuperAdmin = 'super_admin';
    case SchoolAdmin = 'school_admin';
    case Principal = 'principal';
    case VicePrincipal = 'vice_principal';
    case ExaminationOfficer = 'examination_officer';
    case AdmissionsOfficer = 'admissions_officer';
    case ContentManager = 'content_manager';
    case Teacher = 'teacher';
    case Accountant = 'accountant';
    case Staff = 'staff';
    case Parent = 'parent';
    case Student = 'student';

    /**
     * Roles allowed on the command /portal desk.
     *
     * @return list<self>
     */
    public static function portalRoles(): array
    {
        return [
            self::SuperAdmin,
            self::SchoolAdmin,
            self::Principal,
            self::VicePrincipal,
            self::ExaminationOfficer,
            self::AdmissionsOfficer,
            self::ContentManager,
            self::Accountant,
        ];
    }

    /**
     * Roles allowed on the /staff desk.
     *
     * @return list<self>
     */
    public static function staffDeskRoles(): array
    {
        return [
            self::Teacher,
            self::Staff,
        ];
    }

    /**
     * Roles that may be appointed through Admin Users (excludes parent/student).
     *
     * @return list<self>
     */
    public static function appointableDeskRoles(): array
    {
        return [
            ...self::portalRoles(),
            ...self::staffDeskRoles(),
        ];
    }

    /**
     * Comma-separated middleware argument for portal routes.
     */
    public static function portalMiddleware(): string
    {
        return implode(',', array_map(fn (self $role) => $role->value, self::portalRoles()));
    }

    /**
     * Comma-separated middleware argument for staff desk routes.
     */
    public static function staffDeskMiddleware(): string
    {
        return implode(',', array_map(fn (self $role) => $role->value, self::staffDeskRoles()));
    }

    public function isSystemRole(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::Parent, self::Student => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function appointableDeskRoleValues(): array
    {
        return array_map(fn (self $role) => $role->value, self::appointableDeskRoles());
    }
}
