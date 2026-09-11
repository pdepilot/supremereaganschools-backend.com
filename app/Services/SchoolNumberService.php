<?php

namespace App\Services;

use App\Models\AdmissionApplication;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StaffProfile;
use App\Models\StudentProfile;

class SchoolNumberService
{
    public const WING_NURSERY = 'NUR';

    public const WING_PRIMARY = 'PRI';

    public const WING_SECONDARY = 'SEC';

    public const WING_ACTIVITY = 'ACT';

    public const WING_GENERAL = 'GEN';

    public function nextStaffNumber(): string
    {
        $numbers = StaffProfile::withTrashed()
            ->where('staff_number', 'like', 'SRS/TCH/%')
            ->pluck('staff_number');

        $max = $numbers
            ->map(fn (string $number) => (int) substr($number, strlen('SRS/TCH/')))
            ->max() ?: 0;

        return 'SRS/TCH/'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Wing-specific admission numbers so nursery, primary, and secondary stay distinct.
     * Examples: SRS/NUR/2026/0001, SRS/PRI/2026/0001, SRS/SEC/2026/0001
     */
    public function nextAdmissionNumber(?int $year = null, ?string $wing = null): string
    {
        $year ??= (int) now('Africa/Lagos')->year;
        $code = $this->wingCode($wing);
        $prefix = 'SRS/'.$code.'/'.$year.'/';

        $numbers = StudentProfile::withTrashed()
            ->where('admission_number', 'like', $prefix.'%')
            ->pluck('admission_number');

        $max = $numbers
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?: 0;

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Map a level slug / wing label to the short code used in admission numbers.
     */
    public function wingCode(?string $wingOrSlug): string
    {
        $slug = strtolower(trim((string) $wingOrSlug));

        return match (true) {
            in_array($slug, ['nursery', 'nur'], true) => self::WING_NURSERY,
            in_array($slug, ['primary', 'pri', 'basic'], true) => self::WING_PRIMARY,
            in_array($slug, ['secondary', 'sec', 'jss', 'ss', 'junior', 'senior', 'junior secondary', 'senior secondary'], true) => self::WING_SECONDARY,
            in_array($slug, ['activity', 'act'], true) => self::WING_ACTIVITY,
            default => self::WING_GENERAL,
        };
    }

    public function studentLoginEmail(string $admissionNumber): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $admissionNumber));

        return $slug.'@students.supremereaganschools.invalid';
    }

    public function nextInvoiceNumber(?int $year = null): string
    {
        $year ??= (int) now('Africa/Lagos')->year;
        $prefix = 'INV/'.$year.'/';

        $numbers = Invoice::query()
            ->where('number', 'like', $prefix.'%')
            ->pluck('number');

        $max = $numbers
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?: 0;

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function nextPaymentReference(?int $year = null): string
    {
        $year ??= (int) now('Africa/Lagos')->year;
        $prefix = 'SRS-FEE-'.$year.'-';

        $numbers = Payment::query()
            ->where('reference', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('reference');

        $max = $numbers
            ->map(fn (string $reference) => (int) substr($reference, strlen($prefix)))
            ->max() ?: 0;

        return $prefix.str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    public function nextApplicationReference(): string
    {
        $prefix = 'ADM-';

        $numbers = AdmissionApplication::query()
            ->where('reference', 'like', $prefix.'%')
            ->pluck('reference');

        $max = $numbers
            ->map(fn (string $reference) => (int) substr($reference, strlen($prefix)))
            ->max() ?: 0;

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
