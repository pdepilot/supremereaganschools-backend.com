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

        if ($result['payment'] && $result['newly_paid']) {
            event(new OnlinePaymentSettled($result['payment'], true));
        }

        return response()->json(['status' => 'ok']);
    }
}
