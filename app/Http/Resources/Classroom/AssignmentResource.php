<?php

namespace App\Http\Resources\Classroom;

use App\Enums\RoleSlug;
use App\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Assignment
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $due = $this->due_on;
        $overdue = $due !== null && $due->isPast() && ! $due->isToday();
        $mine = $this->viewerSubmission();
        $household = $request->user()?->hasAnyRole(RoleSlug::Student, RoleSlug::Parent) ?? false;

        if ($household && $mine) {
            $status = $mine->isLate() ? 'late' : 'submitted';
        } elseif ($household && $overdue) {
            $status = 'overdue';
        } elseif ($household) {
            $status = 'pending';
        } else {
            $status = $overdue ? 'overdue' : 'pending';
        }

        return [
            'id' => $this->id,
            'class_section_offering_id' => $this->class_section_offering_id,
            'form' => $this->whenLoaded('classSectionOffering', fn () => $this->classSectionOffering?->classSection?->name),
            'subject_id' => $this->subject_id,
            'subject_name' => $this->whenLoaded('subject', fn () => $this->subject?->name),
            'staff_profile_id' => $this->staff_profile_id,
            'staff_name' => $this->whenLoaded('staff', fn () => $this->staff?->user?->name),
            'title' => $this->title,
            'instructions' => $this->instructions,
            'due_on' => $due?->toDateString(),
            'status' => $status,
            'can_submit' => $request->user()?->can('submit', $this->resource) ?? false,
            'submission' => $mine ? (new AssignmentSubmissionResource($mine))->resolve() : null,
            'submitted_count' => $this->whenCounted('submissions'),
            'on_roll' => $this->when(
                $this->relationLoaded('classSectionOffering') && isset($this->classSectionOffering?->on_roll),
                fn () => (int) $this->classSectionOffering->on_roll,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function viewerSubmission()
    {
        if (! $this->relationLoaded('submissions')) {
            return null;
        }

        return $this->submissions->first();
    }
}
