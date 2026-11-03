<?php

namespace App\Http\Resources\Academic;

use App\Models\Campus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campus
 */
class CampusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'geofence_type' => $this->geofence_type ?? 'circle',
            'radius_meters' => $this->radius_meters ?? 100,
            'boundary_coordinates' => $this->boundary_coordinates,
            'gps_accuracy_threshold' => $this->gps_accuracy_threshold ?? 50,
            'operating_hours_start' => $this->operating_hours_start,
            'operating_hours_end' => $this->operating_hours_end,
            'expected_clock_in_time' => $this->expected_clock_in_time,
            'expected_clock_out_time' => $this->expected_clock_out_time,
            'grace_period_minutes' => $this->grace_period_minutes ?? 15,
            'working_days' => $this->working_days,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
