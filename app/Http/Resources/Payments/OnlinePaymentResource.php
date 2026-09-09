<?php

namespace App\Http\Resources\Payments;

use App\Models\OnlinePayment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OnlinePayment
 */
class OnlinePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'reference' => $this->reference,
            'provider' => $this->provider?->value,
            'purpose' => $this->purpose?->value,
            'status' => $this->status?->value,
            'amount_kobo' => $this->amount_kobo,
            'amount_label' => Money::formatNaira((int) $this->amount_kobo),
            'currency' => $this->currency,
            'channel' => $this->channel,
            'gateway_status' => $this->gateway_status,
            'authorization_url' => $this->when(
                $this->status?->value === 'pending',
                $this->authorization_url,
            ),
            'provider_transaction_id' => $this->provider_reference,
            'paid_at' => optional($this->paid_at)?->toIso8601String(),
            'verified_at' => optional($this->verified_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'failure_reason' => $this->when(
                $this->status?->value === 'failed' || $this->status?->value === 'cancelled',
                $this->failure_reason,
            ),
        ];
    }
}
