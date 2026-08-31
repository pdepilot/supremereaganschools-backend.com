<?php

namespace App\Http\Resources\Academic;

use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SchoolClass
 */
class SchoolClassResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level_id' => $this->level_id,
            'name' => $this->name,
            'short_code' => $this->short_code,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'level' => new LevelResource($this->whenLoaded('level')),
            'sections' => ClassSectionResource::collection($this->whenLoaded('sections')),
        ];
    }
}
