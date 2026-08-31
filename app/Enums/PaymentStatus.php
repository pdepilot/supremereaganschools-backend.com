<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Posted = 'posted';
    case Pending = 'pending';
    case Failed = 'failed';
    case Void = 'void';

    public function countsTowardPaid(): bool
    {
        return $this === self::Posted;
    }

    /**
     * @return list<string>
     */
    public static function recordableValues(): array
    {
        return [
            self::Posted->value,
            self::Pending->value,
            self::Failed->value,
        ];
    }
}
