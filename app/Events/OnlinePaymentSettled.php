<?php

namespace App\Events;

use App\Models\OnlinePayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OnlinePaymentSettled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly OnlinePayment $payment,
        public readonly bool $newlyPaid,
    ) {}
}
