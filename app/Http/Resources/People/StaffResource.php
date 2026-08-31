<?php

namespace App\Http\Resources\People;

use App\Enums\RoleSlug;
use App\Models\StaffProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffProfile
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_number' => $this->staff_number,
            'name' => $this->user?->name,
            'email' => $this->when(
                $request->user()?->hasAnyRole(RoleSlug::SuperAdmin, RoleSlug::SchoolAdmin),
                $this->user?->email,
            ),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'gender' => $this->gender?->value,
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'employed_on' => $this->employed_on?->toDateString(),
            'status' => $this->status?->value,
            'account_status' => $this->whenLoaded('user', fn () => $this->user?->status?->value),
            'roles' => $this->whenLoaded('user', fn () => $this->user?->roleSlugs()->values()->all()),
            'subjects' => $this->whenLoaded('subjectTeacherAssignments', function () {
                return $this->subjectTeacherAssignments
                    ->map(fn ($assignment) => $assignment->subjectOffering?->subject?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            }),
            'forms' => $this->whenLoaded('classTeacherAssignments', function () {
                return $this->classTeacherAssignments
                    ->map(fn ($assignment) => $assignment->classSectionOffering?->classSection?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            }),
            'class_section_offering_id' => $this->whenLoaded('classTeacherAssignments', function () {
                return $this->classTeacherAssignments->first()?->class_section_offering_id;
            }),
        ];
    }
}
