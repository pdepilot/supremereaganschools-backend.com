<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fees\EmailPaymentReceiptRequest;
use App\Http\Requests\Fees\StorePaymentRequest;
use App\Http\Requests\Fees\VoidPaymentRequest;
use App\Http\Resources\Fees\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentReceiptService;
use App\Services\PaymentService;
use App\Services\PeopleAccessService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentReceiptService $receipts,
        private readonly PeopleAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()->with($this->payments->defaultRelations());
        $user = $request->user();

        if ($this->access->isStudent($user) && ! $this->access->administers($user)) {
            $query->where('student_profile_id', $user->studentProfile?->id);
        } elseif ($this->access->isParent($user) && ! $this->access->administers($user)) {
            $ids = $this->access->linkedStudentIds($user);
            if ($request->filled('student_profile_id')) {
                if (! $ids->contains($request->integer('student_profile_id'))) {
                    throw new AuthorizationException;
                }
                $ids = collect([$request->integer('student_profile_id')]);
            }
            $query->whereIn('student_profile_id', $ids);
        } elseif ($request->filled('student_profile_id')) {
            $query->where('student_profile_id', $request->integer('student_profile_id'));
        }

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->integer('invoice_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('paid_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('paid_at', '<=', $request->date('to'));
        }

        $status = $request->string('status')->toString();
        if (in_array($status, array_column(PaymentStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
        }

        $limit = $request->integer('limit');
        if ($limit > 0) {
            $query->limit(min($limit, 50));
        }

        $rows = $query->orderByDesc('paid_at')->orderByDesc('id')->get();

        return ApiResponse::success('Payments retrieved.', PaymentResource::collection($rows)->resolve());
    }

    public function mine(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $payment = $this->payments->post($request->validated(), $request->user());

        return ApiResponse::success('Payment posted.', (new PaymentResource($payment))->resolve(), 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return ApiResponse::success(
            'Payment retrieved.',
            (new PaymentResource($payment->load($this->payments->defaultRelations())))->resolve(),
        );
    }

    public function void(VoidPaymentRequest $request, Payment $payment): JsonResponse
    {
        $payment = $this->payments->void($payment, $request->validated(), $request->user());

        return ApiResponse::success('Payment voided.', (new PaymentResource($payment))->resolve());
    }

    public function receipt(Payment $payment): Response
    {
        $this->authorize('view', $payment);

        $payment = $this->receipts->load($payment);

        return response()
            ->view('fees.payment-receipt', $this->receipts->viewData($payment, false))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function email(EmailPaymentReceiptRequest $request, Payment $payment): JsonResponse
    {
        $payment = $this->receipts->load($payment);
        $email = trim((string) ($request->validated('email') ?: $this->receipts->suggestedEmail($payment) ?: ''));

        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => 'Enter an email address for this receipt.',
            ]);
        }

        $this->receipts->send(
            $payment,
            $request->user(),
            $email,
            $request->validated('name'),
        );

        return ApiResponse::success('Receipt emailed.', [
            'payment_id' => $payment->id,
            'email' => $email,
            'reference' => $payment->reference,
        ]);
    }
}
