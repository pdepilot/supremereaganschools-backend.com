<?php

namespace App\Listeners;

use App\Enums\OnlinePaymentPurpose;
use App\Events\OnlinePaymentSettled;
use App\Services\Admissions\AdmissionCheckoutService;

class FinalizeAdmissionApplicationOnPayment
{
    public function __construct(private readonly AdmissionCheckoutService $checkout) {}

    public function handle(OnlinePaymentSettled $event): void
    {
        if ($event->payment->purpose !== OnlinePaymentPurpose::AdmissionApplicationFee) {
            return;
        }

        if (! $event->payment->isPaid()) {
            return;
        }

        $this->checkout->finalizeFromPayment($event->payment);
    }
}
