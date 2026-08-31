<?php

namespace App\Http\Resources\Assessments;

use App\Models\GradeScale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GradeScale
 */
class GradeScaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'min_score' => (float) $this->min_score,
            'max_score' => (float) $this->max_score,
            'grade' => $this->grade,
            'remark' => $this->remark,
            'sort_order' => $this->sort_order,
        ];
    }
}
