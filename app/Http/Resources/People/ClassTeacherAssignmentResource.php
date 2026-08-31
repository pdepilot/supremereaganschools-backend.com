<?php

namespace App\Http\Resources\People;

use App\Models\ClassTeacherAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassTeacherAssignment
 */
class ClassTeacherAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_profile_id' => $this->staff_profile_id,
            'class_section_offering_id' => $this->class_section_offering_id,
            'is_active' => $this->is_active,
            'assigned_on' => $this->assigned_on?->toDateString(),
            'ended_on' => $this->ended_on?->toDateString(),
            'staff_name' => $this->whenLoaded('staff', fn () => $this->staff?->user?->name),
            'staff_number' => $this->whenLoaded('staff', fn () => $this->staff?->staff_number),
            'form' => $this->whenLoaded('classSectionOffering', fn () => $this->classSectionOffering?->classSection?->name),
        ];
    }
}
