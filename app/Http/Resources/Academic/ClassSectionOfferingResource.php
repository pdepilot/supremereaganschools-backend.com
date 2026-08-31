<?php

namespace App\Http\Resources\Academic;

use App\Models\ClassSectionOffering;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassSectionOffering
 */
class ClassSectionOfferingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_section_id' => $this->class_section_id,
            'academic_session_id' => $this->academic_session_id,
            'campus_id' => $this->campus_id,
            'capacity' => $this->capacity,
            'is_active' => $this->is_active,
            'form' => $this->whenLoaded('classSection', fn () => $this->classSection->name),
            'level_id' => $this->whenLoaded('classSection', fn () => $this->classSection?->schoolClass?->level_id),
            'level_name' => $this->whenLoaded('classSection', fn () => $this->classSection?->schoolClass?->level?->name),
            'level_slug' => $this->whenLoaded('classSection', fn () => $this->classSection?->schoolClass?->level?->slug),
            'class_section' => new ClassSectionResource($this->whenLoaded('classSection')),
            'academic_session' => new AcademicSessionResource($this->whenLoaded('academicSession')),
            'campus' => new CampusResource($this->whenLoaded('campus')),
            'class_teacher' => $this->whenLoaded('activeClassTeacher', function () {
                return $this->activeClassTeacher->first()?->staff?->user?->name;
            }),
            'class_teacher_id' => $this->whenLoaded('activeClassTeacher', function () {
                return $this->activeClassTeacher->first()?->staff_profile_id;
            }),
            'class_teacher_assignment_id' => $this->whenLoaded('activeClassTeacher', function () {
                return $this->activeClassTeacher->first()?->id;
            }),
            'enrollment_count' => $this->when(isset($this->enrollments_count), $this->enrollments_count),
            'subjects' => $this->whenLoaded('subjectOfferings', function () {
                return $this->subjectOfferings
                    ->filter(fn ($row) => $row->subject)
                    ->map(fn ($row) => [
                        'id' => $row->subject_id,
                        'offering_id' => $row->id,
                        'name' => $row->subject->name,
                        'code' => $row->subject->code,
                    ])
                    ->values()
                    ->all();
            }),
        ];
    }
}
