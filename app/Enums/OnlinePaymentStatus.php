<?php

namespace App\Enums;

enum OnlinePaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function grantsAccess(): bool
    {
        return $this === self::Paid;
    }
}
