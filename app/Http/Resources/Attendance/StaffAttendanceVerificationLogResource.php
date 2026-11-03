<?php

namespace App\Http\Resources\Attendance;

use App\Models\StaffAttendanceVerificationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffAttendanceVerificationLog
 */
class StaffAttendanceVerificationLogResource extends JsonResource
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
            'action' => $this->action,
            'attempted_at' => $this->attempted_at?->setTimezone('Africa/Lagos')->toIso8601String(),
            'attempted_at_formatted' => $this->attempted_at?->setTimezone('Africa/Lagos')->format('d M Y, h:i:s A'),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'gps_accuracy' => $this->gps_accuracy,
            'distance_meters' => $this->distance_meters,
            'is_within_boundary' => (bool) $this->is_within_boundary,
            'accuracy_acceptable' => (bool) $this->accuracy_acceptable,
            'result' => $this->result,
            'rejection_reason' => $this->rejection_reason,
            'is_suspicious' => (bool) $this->is_suspicious,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'metadata' => $this->metadata,
        ];
    }
}

