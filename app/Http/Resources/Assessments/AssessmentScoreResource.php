<?php

namespace App\Http\Resources\Assessments;

use App\Models\AssessmentScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssessmentScore
 */
class AssessmentScoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'term_id' => $this->term_id,
            'subject_id' => $this->subject_id,
            'assessment_type_id' => $this->assessment_type_id,
            'score' => (float) $this->score,
            'entered_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'admission_number' => $this->whenLoaded('enrollment', fn () => $this->enrollment?->student?->admission_number),
            'student_name' => $this->whenLoaded('enrollment', fn () => $this->enrollment?->student?->fullName()),
            'subject_name' => $this->whenLoaded('subject', fn () => $this->subject?->name),
            'assessment_name' => $this->whenLoaded('assessmentType', fn () => $this->assessmentType?->name),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
