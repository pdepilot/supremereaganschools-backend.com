<?php

namespace App\Http\Resources\Assessments;

use App\Models\AssessmentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssessmentType
 */
class AssessmentTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind?->value,
            'name' => $this->name,
            'max_score' => (float) $this->max_score,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
