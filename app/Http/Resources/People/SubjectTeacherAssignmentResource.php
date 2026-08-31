<?php

namespace App\Http\Resources\People;

use App\Models\SubjectTeacherAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubjectTeacherAssignment
 */
class SubjectTeacherAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_profile_id' => $this->staff_profile_id,
            'subject_offering_id' => $this->subject_offering_id,
            'is_active' => $this->is_active,
            'assigned_on' => $this->assigned_on?->toDateString(),
            'ended_on' => $this->ended_on?->toDateString(),
            'staff_name' => $this->whenLoaded('staff', fn () => $this->staff?->user?->name),
            'subject' => $this->whenLoaded('subjectOffering', fn () => $this->subjectOffering?->subject?->name),
            'form' => $this->whenLoaded('subjectOffering', fn () => $this->subjectOffering?->classSectionOffering?->classSection?->name),
        ];
    }
}
