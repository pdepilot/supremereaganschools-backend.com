<?php

namespace App\Http\Resources\Cbt;

use App\Models\CbtResultCheckerPurchase;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CbtResultCheckerPurchase
 */
class CbtResultCheckerPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['student', 'result.attempt.exam', 'onlinePayment', 'product']);

        return [
            'uuid' => $this->uuid,
            'checker_code' => $this->checker_code,
            'verification_code' => $this->verification_code,
            'cbt_result_id' => $this->cbt_result_id,
            'product_id' => $this->product_id,
            'student_name' => $this->student?->fullName(),
            'exam_title' => $this->result?->attempt?->exam?->title,
            'amount_kobo' => $this->amount_kobo,
            'amount_label' => Money::formatNaira((int) $this->amount_kobo),
            'currency' => $this->currency,
            'status' => $this->status?->value,
            'checks_allowed' => $this->checks_allowed,
            'checks_remaining' => $this->checks_remaining,
            'checked_count' => $this->checked_count,
            'paid_at' => optional($this->paid_at)?->toIso8601String(),
            'expires_at' => optional($this->expires_at)?->toIso8601String(),
            'payment_reference' => $this->onlinePayment?->reference,
            'payment_status' => $this->onlinePayment?->status?->value,
        ];
    }
}
