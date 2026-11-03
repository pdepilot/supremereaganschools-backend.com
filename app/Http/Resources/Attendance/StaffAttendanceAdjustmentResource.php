<?php

namespace App\Http\Resources\Attendance;

use App\Models\StaffAttendanceAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffAttendanceAdjustment
 */
class StaffAttendanceAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_attendance_record_id' => $this->staff_attendance_record_id,
            'staff_name' => $this->attendanceRecord?->staffProfile?->user?->name,
            'staff_number' => $this->attendanceRecord?->staffProfile?->staff_number,
            'campus_name' => $this->attendanceRecord?->campus?->name,
            'record_date' => $this->attendanceRecord?->date?->format('Y-m-d'),
            'requested_by_name' => $this->requester?->name,
            'reviewed_by_name' => $this->reviewer?->name,
            'original_values' => $this->original_values,
            'requested_values' => $this->requested_values,
            'reason' => $this->reason,
            'status' => $this->status,
            'review_notes' => $this->review_notes,
            'reviewed_at' => $this->reviewed_at?->setTimezone('Africa/Lagos')->format('d M Y, h:i A'),
            'created_at' => $this->created_at?->setTimezone('Africa/Lagos')->format('d M Y, h:i A'),
        ];
    }
}

