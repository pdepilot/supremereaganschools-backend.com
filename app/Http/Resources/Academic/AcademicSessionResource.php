<?php

namespace App\Http\Resources\Academic;

use App\Models\AcademicSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AcademicSession
 */
class AcademicSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'term_count' => $this->term_count,
            'status' => $this->status?->value,
            'terms' => TermResource::collection($this->whenLoaded('terms')),
        ];
    }
}
