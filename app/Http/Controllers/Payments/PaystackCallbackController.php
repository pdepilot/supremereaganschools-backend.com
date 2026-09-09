<?php

namespace App\Http\Controllers\Payments;

use App\Enums\OnlinePaymentPurpose;
use App\Events\OnlinePaymentSettled;
use App\Http\Controllers\Controller;
use App\Services\Payments\Paystack\PaystackPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Browser return URL — not the source of truth. Always verifies server-side.
 * Query ?status=success is ignored for settlement.
 */
class PaystackCallbackController extends Controller
{
    public function __construct(private readonly PaystackPaymentService $payments) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));
        if ($reference === '') {
            return redirect($this->statusUrl('missing'));
        }

        try {
            $result = $this->payments->verifyAndSettle($reference);
            $payment = $result['payment'];

            // Idempotent activation: fire whenever the row is paid so missing access can recover.
            if ($payment->isPaid()) {
                event(new OnlinePaymentSettled($payment, (bool) $result['newly_paid']));
            }

            $status = $payment->isPaid() ? 'success' : 'pending';
            if ($payment->status?->value === 'failed' || $payment->status?->value === 'cancelled') {
                $status = 'failed';
            }

            return redirect($this->statusUrl($status, $payment->reference, $payment->purpose));
        } catch (\Throwable $e) {
            report($e);

            return redirect($this->statusUrl('pending', $reference));
        }
    }

    private function statusUrl(string $status, ?string $reference = null, ?OnlinePaymentPurpose $purpose = null): string
    {
        $query = http_build_query(array_filter([
            'status' => $status,
            'reference' => $reference,
        ]));

        if ($purpose === OnlinePaymentPurpose::CbtResultChecker) {
            return '/cbt/result-checker/success?'.$query;
        }

        return '/payments/test/status?'.$query;
    }
}
