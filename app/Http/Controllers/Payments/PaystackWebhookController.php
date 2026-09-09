<?php

namespace App\Http\Controllers\Payments;

use App\Events\OnlinePaymentSettled;
use App\Http\Controllers\Controller;
use App\Services\Payments\Paystack\PaystackPaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Stateless Paystack webhook. CSRF excluded; signature verified on raw body first.
 */
class PaystackWebhookController extends Controller
{
    public function __construct(private readonly PaystackPaymentService $payments) {}

    public function __invoke(Request $request): JsonResponse|Response
    {
        $raw = $request->getContent();
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return ApiResponse::error('Invalid webhook payload.', status: 400);
        }

        try {
            $result = $this->payments->handlePaystackWebhook(
                $raw,
                $request->header('x-paystack-signature'),
                $payload,
            );
        } catch (ValidationException $e) {
            return ApiResponse::error('Invalid webhook signature.', $e->errors(), 400);
        }

        // Fire for every successfully paid payment so a prior settle-without-access
        // recovery path can still grant Result Checker entitlement (listener is idempotent).
        if ($result['payment']?->isPaid()) {
            event(new OnlinePaymentSettled($result['payment'], (bool) $result['newly_paid']));
        }

        return response()->json(['status' => 'ok']);
    }
}
