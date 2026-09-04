<?php

namespace App\Enums;

enum PermissionSlug: string
{
    case Desk = 'desk';
    case Pupils = 'pupils';
    case Staff = 'staff';
    case Academics = 'academics';
    case Timetable = 'timetable';
    case Fees = 'fees';
    case Marks = 'marks';
    case Notices = 'notices';
    case News = 'news';
    case Email = 'email';
    case Contact = 'contact';
    case Messages = 'messages';
    case Reports = 'reports';
    case Settings = 'settings';
    case Admins = 'admins';

    public function label(): string
    {
        return match ($this) {
            self::Desk => 'Command desk',
            self::Pupils => 'Pupils',
            self::Staff => 'Staff',
            self::Academics => 'Classes & sessions',
            self::Timetable => 'Timetable',
            self::Fees => 'Fees',
            self::Marks => 'Marks',
            self::Notices => 'Notices',
            self::News => 'News',
            self::Email => 'Email centre',
            self::Contact => 'Contact desk',
            self::Messages => 'Internal mail',
            self::Reports => 'Reports',
            self::Settings => 'School setup',
            self::Admins => 'Admin accounts',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::Desk, self::Reports, self::Settings, self::Admins => 'Command',
            self::Pupils, self::Staff, self::Academics, self::Timetable => 'House',
            self::Fees, self::Marks => 'Books',
            self::Notices, self::News, self::Email, self::Contact, self::Messages => 'Voice',
        };
    }

    /**
     * @return list<string>
     */
    public function pages(): array
    {
        return match ($this) {
            self::Desk => ['dashboard'],
            self::Pupils => ['students'],
            self::Staff => ['teachers'],
            self::Academics => ['classes', 'academic_sessions', 'nursery', 'primary', 'secondary'],
            self::Timetable => ['timetable'],
            self::Fees => ['fees'],
            self::Marks => ['grades'],
            self::Notices => ['announcements'],
            self::News => ['news'],
            self::Email => ['email'],
            self::Contact => ['contact'],
            self::Messages => ['messages'],
            self::Reports => ['reports'],
            self::Settings => ['settings'],
            self::Admins => ['admins'],
        };
    }

    /**
     * @return list<self>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $permission) => $permission !== self::Admins,
        ));
    }
}
