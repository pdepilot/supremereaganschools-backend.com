<?php

namespace App\Http\Resources\Fees;

use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $remaining = max(0, (int) $this->total_kobo - (int) $this->paid_kobo);
        $percent = (int) $this->total_kobo > 0
            ? round(((int) $this->paid_kobo / (int) $this->total_kobo) * 100, 1)
            : 0.0;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'student_profile_id' => $this->student_profile_id,
            'enrollment_id' => $this->enrollment_id,
            'academic_session_id' => $this->academic_session_id,
            'term_id' => $this->term_id,
            'status' => $this->status?->value,
            'fee_status' => $this->status?->feeStatus(),
            'fee_status_label' => $this->status?->feeStatusLabel(),
            'total_kobo' => $this->total_kobo,
            'paid_kobo' => $this->paid_kobo,
            'balance_kobo' => $remaining,
            'total_naira' => Money::toNaira((int) $this->total_kobo),
            'paid_naira' => Money::toNaira((int) $this->paid_kobo),
            'balance_naira' => Money::toNaira($remaining),
            'percentage_paid' => $percent,
            'due_on' => $this->due_on?->toDateString(),
            'student_name' => $this->whenLoaded('student', fn () => $this->student?->fullName()),
            'admission_number' => $this->whenLoaded('student', fn () => $this->student?->admission_number),
            'form' => $this->whenLoaded('enrollment', fn () => $this->enrollment?->classSectionOffering?->classSection?->name),
            'session_name' => $this->whenLoaded('academicSession', fn () => $this->academicSession?->name),
            'term_name' => $this->whenLoaded('term', fn () => $this->term?->name),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
