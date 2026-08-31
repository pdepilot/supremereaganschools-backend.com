<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Void = 'void';

    public function feeStatus(): string
    {
        return match ($this) {
            self::Paid => 'paid_in_full',
            self::Partial => 'partially_paid',
            self::Unpaid => 'outstanding',
            self::Void => 'void',
        };
    }

    public function feeStatusLabel(): string
    {
        return match ($this) {
            self::Paid => 'Paid in Full',
            self::Partial => 'Partially Paid',
            self::Unpaid => 'Outstanding',
            self::Void => 'Void',
        };
    }

    public static function fromFilter(?string $value): ?self
    {
        return match ($value) {
            'paid', 'paid_in_full' => self::Paid,
            'partial', 'partially_paid' => self::Partial,
            'unpaid', 'outstanding' => self::Unpaid,
            'void' => self::Void,
            default => self::tryFrom((string) $value),
        };
    }
}
