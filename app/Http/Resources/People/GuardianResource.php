<?php

namespace App\Http\Resources\People;

use App\Enums\RoleSlug;
use App\Models\GuardianProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GuardianProfile
 */
class GuardianResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $admin = $request->user()?->hasAnyRole(RoleSlug::SuperAdmin, RoleSlug::SchoolAdmin) ?? false;

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'alternate_phone' => $this->when($admin, $this->alternate_phone),
            'email' => $this->when($admin || $request->user()?->guardianProfile?->id === $this->id, $this->email),
            'occupation' => $this->when($admin, $this->occupation),
            'address' => $this->when($admin, $this->address),
            'relationship' => $this->when(isset($this->pivot), fn () => $this->pivot->relationship),
            'is_primary' => $this->when(isset($this->pivot), fn () => (bool) $this->pivot->is_primary),
            'has_login' => $this->user_id !== null,
            'students' => $this->whenLoaded('students', function () {
                return $this->students->map(fn ($student) => [
                    'id' => $student->id,
                    'admission_number' => $student->admission_number,
                    'full_name' => $student->fullName(),
                ])->values()->all();
            }),
        ];
    }
}
