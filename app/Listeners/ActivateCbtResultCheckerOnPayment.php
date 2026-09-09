<?php

namespace App\Listeners;

use App\Enums\OnlinePaymentPurpose;
use App\Events\OnlinePaymentSettled;
use App\Services\Cbt\CbtResultCheckerService;

/**
 * Existing Phase 8B entitlement hook — kept out of payment controllers.
 */
class ActivateCbtResultCheckerOnPayment
{
    public function __construct(private readonly CbtResultCheckerService $checkers) {}

    public function handle(OnlinePaymentSettled $event): void
    {
        if ($event->payment->purpose !== OnlinePaymentPurpose::CbtResultChecker) {
            return;
        }

        if (! $event->payment->isPaid()) {
            return;
        }

        // Idempotent: grants access when missing; reuses the row when already present.
        $this->checkers->activateFromPayment($event->payment);
    }
}
