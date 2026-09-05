<?php

namespace App\Enums;

enum PermissionSlug: string
{
    case DeskView = 'desk.view';
    case DeskAdminister = 'desk.administer';

    case StudentsView = 'students.view';
    case StudentsCreate = 'students.create';
    case StudentsEdit = 'students.edit';
    case StudentsDelete = 'students.delete';

    case StaffView = 'staff.view';
    case StaffCreate = 'staff.create';
    case StaffEdit = 'staff.edit';
    case StaffDelete = 'staff.delete';

    case GuardiansView = 'guardians.view';
    case GuardiansCreate = 'guardians.create';
    case GuardiansEdit = 'guardians.edit';
    case GuardiansDelete = 'guardians.delete';

    case AcademicsView = 'academics.view';
    case AcademicsManage = 'academics.manage';

    case TimetableView = 'timetable.view';
    case TimetableManage = 'timetable.manage';

    case AttendanceView = 'attendance.view';
    case AttendanceManage = 'attendance.manage';

    case MarksView = 'marks.view';
    case MarksManage = 'marks.manage';

    case FeesView = 'fees.view';
    case FeesManage = 'fees.manage';
    case PaymentsView = 'payments.view';
    case PaymentsManage = 'payments.manage';

    case AdmissionsView = 'admissions.view';
    case AdmissionsManage = 'admissions.manage';

    case NoticesView = 'notices.view';
    case NoticesManage = 'notices.manage';

    case NewsView = 'news.view';
    case NewsManage = 'news.manage';

    case EmailView = 'email.view';
    case EmailManage = 'email.manage';

    case ContactView = 'contact.view';
    case ContactManage = 'contact.manage';

    case MessagesView = 'messages.view';
    case MessagesManage = 'messages.manage';

    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    case SettingsView = 'settings.view';
    case SettingsEdit = 'settings.edit';

    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesEdit = 'roles.edit';
    case RolesDelete = 'roles.delete';

    case PermissionsView = 'permissions.view';

    case AdminsView = 'admins.view';
    case AdminsCreate = 'admins.create';
    case AdminsEdit = 'admins.edit';
    case AdminsSuspend = 'admins.suspend';
    case AdminsDelete = 'admins.delete';

    public function label(): string
    {
        return match ($this) {
            self::DeskView => 'View command desk',
            self::DeskAdminister => 'Full office authority',
            self::StudentsView => 'View pupils',
            self::StudentsCreate => 'Register pupils',
            self::StudentsEdit => 'Edit pupils',
            self::StudentsDelete => 'Remove pupils',
            self::StaffView => 'View staff',
            self::StaffCreate => 'Appoint staff',
            self::StaffEdit => 'Edit staff',
            self::StaffDelete => 'Remove staff',
            self::GuardiansView => 'View guardians',
            self::GuardiansCreate => 'Create guardians',
            self::GuardiansEdit => 'Edit guardians',
            self::GuardiansDelete => 'Remove guardians',
            self::AcademicsView => 'View classes & sessions',
            self::AcademicsManage => 'Manage classes & sessions',
            self::TimetableView => 'View timetable',
            self::TimetableManage => 'Manage timetable',
            self::AttendanceView => 'View attendance',
            self::AttendanceManage => 'Manage attendance',
            self::MarksView => 'View marks',
            self::MarksManage => 'Manage marks',
            self::FeesView => 'View fees',
            self::FeesManage => 'Manage fees',
            self::PaymentsView => 'View payments',
            self::PaymentsManage => 'Record payments',
            self::AdmissionsView => 'View admissions',
            self::AdmissionsManage => 'Manage admissions',
            self::NoticesView => 'View notices',
            self::NoticesManage => 'Manage notices',
            self::NewsView => 'View news',
            self::NewsManage => 'Manage news',
            self::EmailView => 'View email centre',
            self::EmailManage => 'Manage email centre',
            self::ContactView => 'View contact desk',
            self::ContactManage => 'Manage contact desk',
            self::MessagesView => 'View internal mail',
            self::MessagesManage => 'Manage internal mail',
            self::ReportsView => 'View reports',
            self::ReportsExport => 'Export reports',
            self::SettingsView => 'View school setup',
            self::SettingsEdit => 'Edit school setup',
            self::RolesView => 'View roles',
            self::RolesCreate => 'Create roles',
            self::RolesEdit => 'Edit roles',
            self::RolesDelete => 'Delete roles',
            self::PermissionsView => 'View permissions',
            self::AdminsView => 'View admin users',
            self::AdminsCreate => 'Create admin users',
            self::AdminsEdit => 'Edit admin users',
            self::AdminsSuspend => 'Suspend admin users',
            self::AdminsDelete => 'Delete admin users',
        };
    }

    public function module(): string
    {
        return match ($this) {
            self::DeskView, self::DeskAdminister => 'Dashboard',
            self::StudentsView, self::StudentsCreate, self::StudentsEdit, self::StudentsDelete => 'Students',
            self::StaffView, self::StaffCreate, self::StaffEdit, self::StaffDelete => 'Staff',
            self::GuardiansView, self::GuardiansCreate, self::GuardiansEdit, self::GuardiansDelete => 'Parents',
            self::AcademicsView, self::AcademicsManage => 'Classes',
            self::TimetableView, self::TimetableManage => 'Timetable',
            self::AttendanceView, self::AttendanceManage => 'Attendance',
            self::MarksView, self::MarksManage => 'Results',
            self::FeesView, self::FeesManage, self::PaymentsView, self::PaymentsManage => 'Finance',
            self::AdmissionsView, self::AdmissionsManage => 'Admissions',
            self::NoticesView, self::NoticesManage, self::NewsView, self::NewsManage,
            self::EmailView, self::EmailManage, self::ContactView, self::ContactManage,
            self::MessagesView, self::MessagesManage => 'Content',
            self::ReportsView, self::ReportsExport => 'Reports',
            self::SettingsView, self::SettingsEdit => 'Settings',
            self::RolesView, self::RolesCreate, self::RolesEdit, self::RolesDelete,
            self::PermissionsView => 'Security',
            self::AdminsView, self::AdminsCreate, self::AdminsEdit, self::AdminsSuspend,
            self::AdminsDelete => 'Admin users',
        };
    }

    /**
     * Permissions reserved for Super Administrator unless explicitly assigned.
     */
    public function isSuperAdminOnly(): bool
    {
        return match ($this) {
            self::AdminsView,
            self::AdminsCreate,
            self::AdminsEdit,
            self::AdminsSuspend,
            self::AdminsDelete => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public function pages(): array
    {
        return match ($this) {
            self::DeskView, self::DeskAdminister => ['dashboard'],
            self::StudentsView, self::StudentsCreate, self::StudentsEdit, self::StudentsDelete => ['students', 'nursery', 'primary', 'secondary', 'wing'],
            self::StaffView, self::StaffCreate, self::StaffEdit, self::StaffDelete => ['teachers'],
            self::AcademicsView, self::AcademicsManage => ['classes', 'academic_sessions', 'nursery', 'primary', 'secondary', 'wing'],
            self::TimetableView, self::TimetableManage => ['timetable'],
            self::MarksView, self::MarksManage => ['grades'],
            self::FeesView, self::FeesManage, self::PaymentsView, self::PaymentsManage => ['fees'],
            self::AdmissionsView, self::AdmissionsManage => ['contact'],
            self::NoticesView, self::NoticesManage => ['announcements'],
            self::NewsView, self::NewsManage => ['news'],
            self::EmailView, self::EmailManage => ['email'],
            self::ContactView, self::ContactManage => ['contact'],
            self::MessagesView, self::MessagesManage => ['messages'],
            self::ReportsView, self::ReportsExport => ['reports'],
            self::SettingsView, self::SettingsEdit => ['settings'],
            self::RolesView, self::RolesCreate, self::RolesEdit, self::RolesDelete,
            self::PermissionsView => ['roles'],
            self::AdminsView, self::AdminsCreate, self::AdminsEdit, self::AdminsSuspend,
            self::AdminsDelete => ['admins'],
            default => [],
        };
    }

    /**
     * @return list<self>
     */
    public static function allCases(): array
    {
        return self::cases();
    }

    /**
     * Permissions that ordinary desk roles may receive by default.
     *
     * @return list<self>
     */
    public static function assignableToDeskRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $permission) => ! $permission->isSuperAdminOnly(),
        ));
    }
}
