<?php

namespace App\Http\Resources\Attendance;

use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceRecord
 */
class AttendanceRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'class_section_offering_id' => $this->class_section_offering_id,
            'marked_on' => $this->marked_on?->toDateString(),
            'status' => $this->status?->value,
            'remark' => $this->remark,
            'marked_by' => $this->whenLoaded('marker', fn () => $this->marker?->name),
            'form' => $this->whenLoaded('classSectionOffering', fn () => $this->classSectionOffering?->classSection?->name),
            'student_profile_id' => $this->enrollment?->student_profile_id,
            'admission_number' => $this->whenLoaded('enrollment', fn () => $this->enrollment?->student?->admission_number),
            'student_name' => $this->whenLoaded('enrollment', fn () => $this->enrollment?->student?->fullName()),
            'corrections' => $this->whenLoaded('corrections', function () {
                return $this->corrections->map(fn ($correction) => [
                    'id' => $correction->id,
                    'from_status' => $correction->from_status?->value,
                    'to_status' => $correction->to_status?->value,
                    'reason' => $correction->reason,
                    'corrected_by' => $correction->corrector?->name,
                    'corrected_at' => $correction->created_at?->toIso8601String(),
                ])->values()->all();
            }),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
