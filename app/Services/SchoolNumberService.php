<?php

namespace App\Services;

use App\Models\AdmissionApplication;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StaffProfile;
use App\Models\StudentProfile;

class SchoolNumberService
{
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

    public function nextAdmissionNumber(?int $year = null): string
    {
        $year ??= (int) now('Africa/Lagos')->year;
        $prefix = 'SRS/'.$year.'/';

        $numbers = StudentProfile::withTrashed()
            ->where('admission_number', 'like', $prefix.'%')
            ->pluck('admission_number');

        $max = $numbers
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?: 0;

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
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
