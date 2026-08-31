<?php

namespace App\Http\Resources\Admissions;

use App\Models\AdmissionApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdmissionApplication
 */
class AdmissionApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'session_name' => $this->session_name,
            'academic_session_id' => $this->academic_session_id,
            'level_id' => $this->level_id,
            'level_name' => $this->whenLoaded('level', fn () => $this->level?->name),
            'class_applied' => $this->class_applied,
            'entry_term' => $this->entry_term,
            'surname' => $this->surname,
            'first_name' => $this->first_name,
            'other_names' => $this->other_names,
            'full_name' => $this->fullName(),
            'gender' => $this->gender?->value,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'nationality' => $this->nationality,
            'state_of_origin' => $this->state_of_origin,
            'lga' => $this->lga,
            'home_address' => $this->home_address,
            'previous_school' => $this->previous_school,
            'last_class' => $this->last_class,
            'parent_name' => $this->parent_name,
            'relationship' => $this->relationship?->value,
            'parent_phone' => $this->parent_phone,
            'parent_email' => $this->parent_email,
            'parent_occupation' => $this->parent_occupation,
            'parent_second_phone' => $this->parent_second_phone,
            'parent_address' => $this->parent_address,
            'blood_group' => $this->blood_group,
            'genotype' => $this->genotype,
            'allergies' => $this->allergies,
            'interests' => $this->interests,
            'status' => $this->status?->value,
            'student_profile_id' => $this->student_profile_id,
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
