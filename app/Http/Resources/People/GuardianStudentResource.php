<?php

namespace App\Http\Resources\People;

use App\Models\GuardianStudent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GuardianStudent
 */
class GuardianStudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'guardian_profile_id' => $this->guardian_profile_id,
            'student_profile_id' => $this->student_profile_id,
            'relationship' => $this->relationship?->value,
            'is_primary' => $this->is_primary,
            'can_login' => $this->can_login,
            'guardian' => new GuardianResource($this->whenLoaded('guardian')),
            'student' => new StudentResource($this->whenLoaded('student')),
        ];
    }
}
