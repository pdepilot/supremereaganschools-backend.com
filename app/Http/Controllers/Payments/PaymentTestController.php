<?php

namespace App\Http\Controllers\Payments;

use App\Enums\OnlinePaymentPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\Payments\OnlinePaymentResource;
use App\Models\OnlinePayment;
use App\Models\StudentProfile;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackPaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Controlled Phase 8A test-payment surface — not a production pricing catalog.
 */
class PaymentTestController extends Controller
{
    public function __construct(
        private readonly PaystackPaymentService $payments,
        private readonly PaystackClient $paystack,
    ) {}

    public function page(Request $request): Response
    {
        abort_unless($this->payments->testPaymentsEnabled(), 404);

        $html = file_get_contents(resource_path('frontend/payments/test.html'));
        abort_if($html === false, 500);

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function statusPage(Request $request): Response
    {
        abort_unless($this->payments->testPaymentsEnabled(), 404);

        $html = file_get_contents(resource_path('frontend/payments/status.html'));
        abort_if($html === false, 500);

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function bootstrap(Request $request): JsonResponse
    {
        abort_unless($this->payments->testPaymentsEnabled(), 404);
        $this->authorize('create', OnlinePayment::class);

        $amount = $this->payments->testAmountKobo();

        return ApiResponse::success('Paystack test payment bootstrap.', [
            'amount_kobo' => $amount,
            'amount_label' => \App\Support\Money::formatNaira($amount),
            'currency' => $this->paystack->currency(),
            'paystack_public_key' => $this->paystack->publicKey(),
            'test_mode' => true,
        ]);
    }

    public function initialize(Request $request): JsonResponse
    {
        abort_unless($this->payments->testPaymentsEnabled(), 404);
        $this->authorize('create', OnlinePayment::class);

        $user = $request->user();
        abort_unless($user !== null, 401);

        // Browser may send amount; it is ignored — server amount wins.
        $request->validate([
            'amount' => ['sometimes', 'nullable', 'integer'],
            'amount_kobo' => ['sometimes', 'nullable', 'integer'],
        ]);

        $email = strtolower(trim((string) $user->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || str_ends_with($email, '.invalid')) {
            throw ValidationException::withMessages([
                'email' => 'A valid account email is required for checkout.',
            ]);
        }

        $student = StudentProfile::query()->where('user_id', $user->id)->first();
        $amount = $this->payments->testAmountKobo();

        $payment = $this->payments->initialize([
            'email' => $email,
            'amount_kobo' => $amount,
            'currency' => $this->paystack->currency(),
            'purpose' => OnlinePaymentPurpose::TestPayment,
            'metadata' => [
                'source' => 'phase8a_test_payment',
            ],
        ], $user, $student);

        return ApiResponse::success('Checkout started.', [
            'reference' => $payment->reference,
            'status' => $payment->status?->value,
            'amount_kobo' => $payment->amount_kobo,
            'currency' => $payment->currency,
            'authorization_url' => $payment->authorization_url,
            'payment' => (new OnlinePaymentResource($payment))->resolve(),
        ]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $payment = $this->payments->findByReference($reference);
        abort_if($payment === null, 404);
        $this->authorize('view', $payment);

        return ApiResponse::success('Payment retrieved.', [
            'payment' => (new OnlinePaymentResource($payment))->resolve(),
        ]);
    }

    public function verify(Request $request, string $reference): JsonResponse
    {
        $payment = $this->payments->findByReference($reference);
        abort_if($payment === null, 404);
        $this->authorize('view', $payment);

        try {
            $result = $this->payments->verifyAndSettle($reference);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                $e->getMessage() ?: 'Unable to verify payment.',
                $e->errors(),
                422,
            );
        }

        if ($result['newly_paid']) {
            event(new \App\Events\OnlinePaymentSettled($result['payment'], true));
        }

        $fresh = $result['payment']->fresh() ?? $result['payment'];

        return ApiResponse::success(
            $fresh->isPaid()
                ? 'Payment verified.'
                : 'Payment status is being confirmed.',
            [
                'payment' => (new OnlinePaymentResource($fresh))->resolve(),
                'newly_paid' => $result['newly_paid'],
            ],
        );
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OnlinePayment::class);

        $rows = OnlinePayment::query()
            ->with(['user', 'student'])
            ->orderByDesc('id')
            ->paginate(20);

        return ApiResponse::success('Online payments retrieved.', [
            'items' => OnlinePaymentResource::collection($rows)->resolve(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }
}
