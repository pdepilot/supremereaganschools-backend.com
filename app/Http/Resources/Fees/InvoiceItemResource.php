<?php

namespace App\Http\Resources\Fees;

use App\Models\InvoiceItem;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceItem
 */
class InvoiceItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $remaining = max(0, (int) $this->amount_kobo - (int) $this->paid_kobo);

        return [
            'id' => $this->id,
            'fee_type_id' => $this->fee_type_id,
            'fee_type' => $this->whenLoaded('feeType', fn () => $this->feeType?->name),
            'description' => $this->description,
            'amount_kobo' => $this->amount_kobo,
            'paid_kobo' => $this->paid_kobo,
            'balance_kobo' => $remaining,
            'amount_naira' => Money::toNaira((int) $this->amount_kobo),
            'paid_naira' => Money::toNaira((int) $this->paid_kobo),
            'balance_naira' => Money::toNaira($remaining),
            'status' => $remaining <= 0 ? 'paid' : ((int) $this->paid_kobo > 0 ? 'partial' : 'unpaid'),
        ];
    }
}
