<?php

namespace App\Http\Resources\Fees;

use App\Models\FeeStructure;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FeeStructure
 */
class FeeStructureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fee_type_id' => $this->fee_type_id,
            'fee_type' => $this->whenLoaded('feeType', fn () => $this->feeType?->name),
            'fee_type_code' => $this->whenLoaded('feeType', fn () => $this->feeType?->code),
            'academic_session_id' => $this->academic_session_id,
            'session_name' => $this->whenLoaded('academicSession', fn () => $this->academicSession?->name),
            'term_id' => $this->term_id,
            'term_name' => $this->whenLoaded('term', fn () => $this->term?->name),
            'level_id' => $this->level_id,
            'school_class_id' => $this->school_class_id,
            'amount_kobo' => $this->amount_kobo,
            'amount_naira' => Money::toNaira((int) $this->amount_kobo),
            'level_name' => $this->whenLoaded('level', fn () => $this->level?->name),
            'class_name' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass?->name),
        ];
    }
}
