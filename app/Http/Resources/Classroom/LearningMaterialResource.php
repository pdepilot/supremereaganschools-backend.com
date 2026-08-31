<?php

namespace App\Http\Resources\Classroom;

use App\Http\Resources\Admissions\DocumentResource;
use App\Models\LearningMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LearningMaterial
 */
class LearningMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_section_offering_id' => $this->class_section_offering_id,
            'form' => $this->whenLoaded('classSectionOffering', fn () => $this->classSectionOffering?->classSection?->name),
            'subject_id' => $this->subject_id,
            'subject_name' => $this->whenLoaded('subject', fn () => $this->subject?->name),
            'staff_profile_id' => $this->staff_profile_id,
            'staff_name' => $this->whenLoaded('staff', fn () => $this->staff?->user?->name),
            'title' => $this->title,
            'document' => new DocumentResource($this->whenLoaded('document')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
