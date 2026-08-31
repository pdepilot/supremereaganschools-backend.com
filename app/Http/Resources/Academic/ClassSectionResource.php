<?php

namespace App\Http\Resources\Academic;

use App\Models\ClassSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassSection
 */
class ClassSectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_class_id' => $this->school_class_id,
            'arm' => $this->arm,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'school_class' => new SchoolClassResource($this->whenLoaded('schoolClass')),
        ];
    }
}
