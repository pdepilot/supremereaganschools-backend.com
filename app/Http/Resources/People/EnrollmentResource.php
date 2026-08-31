<?php

namespace App\Http\Resources\People;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Enrollment
 */
class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $offering = $this->whenLoaded('classSectionOffering') ? $this->classSectionOffering : null;
        $section = $offering?->classSection;

        return [
            'id' => $this->id,
            'student_profile_id' => $this->student_profile_id,
            'class_section_offering_id' => $this->class_section_offering_id,
            'academic_session_id' => $this->academic_session_id,
            'status' => $this->status?->value,
            'enrolled_on' => $this->enrolled_on?->toDateString(),
            'left_on' => $this->left_on?->toDateString(),
            'form' => $section?->name,
            'class_name' => $section?->schoolClass?->name,
            'arm' => $section?->arm,
            'session_name' => $this->whenLoaded('academicSession', fn () => $this->academicSession?->name),
            'student' => new StudentResource($this->whenLoaded('student')),
        ];
    }
}
