<?php

namespace App\Http\Resources\Cbt;

use App\Models\CbtResultProduct;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CbtResultProduct
 */
class CbtResultProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'amount_kobo' => $this->amount_kobo,
            'amount_label' => Money::formatNaira((int) $this->amount_kobo),
            'currency' => $this->currency,
            'checks_allowed' => $this->checks_allowed,
            'duration_days' => $this->duration_days,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
