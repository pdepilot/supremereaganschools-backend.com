<?php

namespace App\Http\Controllers\Api\V1\Cbt;

use App\Enums\CbtResultCheckerPurchaseStatus;
use App\Enums\OnlinePaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Cbt\CbtResultCheckerPurchaseResource;
use App\Http\Resources\Cbt\CbtResultProductResource;
use App\Models\CbtResultCheckerPurchase;
use App\Models\CbtResultProduct;
use App\Models\OnlinePayment;
use App\Models\SchoolSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CbtAdminResultCheckerController extends Controller
{
    public function products(): JsonResponse
    {
        $this->authorize('manage', CbtResultProduct::class);

        $items = CbtResultProduct::query()->orderBy('id')->get();

        return ApiResponse::success('Result Checker products retrieved.', [
            'items' => CbtResultProductResource::collection($items)->resolve(),
            'details_require_payment' => (bool) (SchoolSetting::query()->value('cbt_result_details_require_payment') ?? true),
        ]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $this->authorize('manage', CbtResultProduct::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'unique:cbt_result_products,code'],
            'description' => ['nullable', 'string'],
            'amount_kobo' => ['required', 'integer', 'min:100'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'checks_allowed' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $product = CbtResultProduct::query()->create([
            ...$validated,
            'currency' => strtoupper((string) ($validated['currency'] ?? 'NGN')),
            'checks_allowed' => $validated['checks_allowed'] ?? 1,
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $request->user()?->id,
        ]);

        return ApiResponse::success('Result Checker product created.', (new CbtResultProductResource($product))->resolve(), 201);
    }

    public function updateProduct(Request $request, CbtResultProduct $product): JsonResponse
    {
        $this->authorize('manage', $product);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:64', Rule::unique('cbt_result_products', 'code')->ignore($product->id)],
            'description' => ['sometimes', 'nullable', 'string'],
            'amount_kobo' => ['sometimes', 'integer', 'min:100'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'checks_allowed' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        $product->update($validated);

        return ApiResponse::success('Result Checker product updated.', (new CbtResultProductResource($product->fresh() ?? $product))->resolve());
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        $this->authorize('manage', CbtResultProduct::class);

        $validated = $request->validate([
            'cbt_result_details_require_payment' => ['required', 'boolean'],
        ]);

        $settings = SchoolSetting::query()->first();
        abort_if($settings === null, 404, 'School settings have not been created.');

        $settings->update([
            'cbt_result_details_require_payment' => $validated['cbt_result_details_require_payment'],
        ]);

        return ApiResponse::success('Result Checker policy updated.', [
            'details_require_payment' => (bool) $settings->cbt_result_details_require_payment,
        ]);
    }

    public function purchases(Request $request): JsonResponse
    {
        $this->authorize('manage', CbtResultProduct::class);

        $rows = CbtResultCheckerPurchase::query()
            ->with(['product', 'onlinePayment', 'student', 'result.attempt.exam'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('id')
            ->paginate(20);

        return ApiResponse::success('Result Checker purchases retrieved.', [
            'items' => CbtResultCheckerPurchaseResource::collection($rows)->resolve(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function statistics(): JsonResponse
    {
        $this->authorize('manage', CbtResultProduct::class);

        $purchases = CbtResultCheckerPurchase::query();
        $payments = OnlinePayment::query()->where('purpose', 'cbt_result_checker');

        return ApiResponse::success('Result Checker statistics retrieved.', [
            'purchases_total' => (clone $purchases)->count(),
            'purchases_pending' => (clone $purchases)->where('status', CbtResultCheckerPurchaseStatus::Pending)->count(),
            'purchases_paid' => (clone $purchases)->where('status', CbtResultCheckerPurchaseStatus::Paid)->count(),
            'purchases_exhausted' => (clone $purchases)->where('status', CbtResultCheckerPurchaseStatus::Exhausted)->count(),
            'purchases_expired' => (clone $purchases)->where('status', CbtResultCheckerPurchaseStatus::Expired)->count(),
            'purchases_refunded' => (clone $purchases)->where('status', CbtResultCheckerPurchaseStatus::Refunded)->count(),
            'payments_pending' => (clone $payments)->where('status', OnlinePaymentStatus::Pending)->count(),
            'payments_paid' => (clone $payments)->where('status', OnlinePaymentStatus::Paid)->count(),
            'payments_failed' => (clone $payments)->where('status', OnlinePaymentStatus::Failed)->count(),
            'revenue_kobo' => (int) (clone $payments)->where('status', OnlinePaymentStatus::Paid)->sum('amount_kobo'),
            'active_checkers' => CbtResultCheckerPurchase::query()
                ->where('status', CbtResultCheckerPurchaseStatus::Paid)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
        ]);
    }
}
