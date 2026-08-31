<?php

namespace App\Http\Resources\Fees;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'student_profile_id' => $this->student_profile_id,
            'invoice_id' => $this->invoice_id,
            'amount_kobo' => $this->amount_kobo,
            'amount_naira' => Money::toNaira((int) $this->amount_kobo),
            'channel' => $this->channel?->value,
            'note' => $this->note,
            'paid_at' => $this->paid_at?->timezone('Africa/Lagos')->toIso8601String(),
            'status' => $this->status?->value,
            'student_name' => $this->whenLoaded('student', fn () => $this->student?->fullName()),
            'admission_number' => $this->whenLoaded('student', fn () => $this->student?->admission_number),
            'form' => $this->whenLoaded('invoice', fn () => $this->invoice?->enrollment?->classSectionOffering?->classSection?->name),
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice?->number),
            'enrollment_id' => $this->whenLoaded('invoice', fn () => $this->invoice?->enrollment_id),
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'voided_by' => $this->whenLoaded('voider', fn () => $this->voider?->name),
            'voided_at' => $this->voided_at?->timezone('Africa/Lagos')->toIso8601String(),
            'void_reason' => $this->void_reason,
            'allocations' => $this->whenLoaded('allocations', function () {
                return $this->allocations->map(fn ($allocation) => [
                    'invoice_item_id' => $allocation->invoice_item_id,
                    'description' => $allocation->invoiceItem?->description,
                    'amount_kobo' => $allocation->amount_kobo,
                    'amount_naira' => Money::toNaira((int) $allocation->amount_kobo),
                ])->values()->all();
            }),
        ];
    }
}
