<?php

namespace App\Http\Resources\Attendance;

use App\Models\StaffCampusAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffCampusAssignment
 */
class StaffCampusAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_profile_id' => $this->staff_profile_id,
            'staff_name' => $this->staffProfile?->user?->name,
            'staff_number' => $this->staffProfile?->staff_number,
            'campus_id' => $this->campus_id,
            'campus_name' => $this->campus?->name,
            'is_primary' => (bool) $this->is_primary,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'is_active' => (bool) $this->is_active,
            'is_current' => $this->isCurrent(),
            'assigned_by_name' => $this->assigner?->name,
            'notes' => $this->notes,
        ];
    }
}

