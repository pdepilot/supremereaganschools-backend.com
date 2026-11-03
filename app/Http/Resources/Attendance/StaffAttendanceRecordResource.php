<?php

namespace App\Http\Resources\Attendance;

use App\Models\StaffAttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffAttendanceRecord
 */
class StaffAttendanceRecordResource extends JsonResource
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
            'department_name' => $this->staffProfile?->department?->name,
            'campus_id' => $this->campus_id,
            'campus_name' => $this->campus?->name,
            'clock_out_campus_id' => $this->clock_out_campus_id,
            'clock_out_campus_name' => $this->clockOutCampus?->name,
            'is_cross_campus' => $this->clock_out_campus_id !== null && $this->clock_out_campus_id !== $this->campus_id,
            'date' => $this->date?->format('Y-m-d'),
            'clock_in_at' => $this->clock_in_at?->setTimezone('Africa/Lagos')->toIso8601String(),
            'clock_in_formatted' => $this->clock_in_at?->setTimezone('Africa/Lagos')->format('h:i A'),
            'clock_out_at' => $this->clock_out_at?->setTimezone('Africa/Lagos')->toIso8601String(),
            'clock_out_formatted' => $this->clock_out_at?->setTimezone('Africa/Lagos')->format('h:i A'),
            'duration_minutes' => $this->durationMinutes(),
            'duration_formatted' => $this->durationMinutes() !== null
                ? sprintf('%dh %02dm', intdiv($this->durationMinutes(), 60), $this->durationMinutes() % 60)
                : null,
            'clock_in_latitude' => $this->clock_in_latitude,
            'clock_in_longitude' => $this->clock_in_longitude,
            'clock_in_accuracy' => $this->clock_in_accuracy,
            'clock_in_distance_meters' => $this->clock_in_distance_meters,
            'clock_out_latitude' => $this->clock_out_latitude,
            'clock_out_longitude' => $this->clock_out_longitude,
            'clock_out_accuracy' => $this->clock_out_accuracy,
            'clock_out_distance_meters' => $this->clock_out_distance_meters,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'badge_class' => $this->status?->badgeClass(),
            'is_flagged' => (bool) $this->is_flagged,
            'flag_reason' => $this->flag_reason,
            'notes' => $this->notes,
            'verified_by' => $this->verifier?->name,
            'has_pending_adjustment' => $this->adjustments?->where('status', 'pending')->isNotEmpty() ?? false,
        ];
    }
}
