<?php

namespace App\Enums;

enum CbtResultCheckerPurchaseStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function isUsable(): bool
    {
        return $this === self::Paid;
    }
}
