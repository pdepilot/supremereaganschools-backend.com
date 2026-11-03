<?php

namespace App\Enums;

enum StaffAttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case EarlyDeparture = 'early_departure';
    case Absent = 'absent';
    case MissingClockOut = 'missing_clock_out';
    case PendingReview = 'pending_review';
    case ApprovedException = 'approved_exception';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late Arrival',
            self::EarlyDeparture => 'Early Departure',
            self::Absent => 'Absent',
            self::MissingClockOut => 'Missing Clock-Out',
            self::PendingReview => 'Pending Review',
            self::ApprovedException => 'Approved Exception',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Late => 'warning',
            self::EarlyDeparture => 'info',
            self::Absent => 'danger',
            self::MissingClockOut => 'secondary',
            self::PendingReview => 'warning',
            self::ApprovedException => 'primary',
        };
    }
}

