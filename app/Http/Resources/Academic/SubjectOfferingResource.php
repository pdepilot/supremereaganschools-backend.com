<?php

namespace App\Http\Resources\Academic;

use App\Models\SubjectOffering;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubjectOffering
 */
class SubjectOfferingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_section_offering_id' => $this->class_section_offering_id,
            'subject_id' => $this->subject_id,
            'class_section_offering' => new ClassSectionOfferingResource($this->whenLoaded('classSectionOffering')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
        ];
    }
}
