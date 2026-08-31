<?php

namespace App\Http\Resources\Academic;

use App\Models\SchoolSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SchoolSetting
 */
class SchoolSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'motto' => $this->motto,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'phone' => $this->phone,
            'email' => $this->email,
            'admissions_email' => $this->admissions_email,
            'whatsapp' => $this->whatsapp,
            'website' => $this->website,
            'timezone' => $this->timezone,
            'founded_on' => $this->founded_on?->toDateString(),
            'office_opens_at' => $this->clock($this->office_opens_at),
            'office_closes_at' => $this->clock($this->office_closes_at),
            'logo_path' => $this->logo_path,
            'current_academic_session_id' => $this->current_academic_session_id,
            'current_term_id' => $this->current_term_id,
            'current_academic_session' => new AcademicSessionResource($this->whenLoaded('currentAcademicSession')),
            'current_term' => new TermResource($this->whenLoaded('currentTerm')),
        ];
    }

    private function clock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = is_string($value) ? $value : (string) $value;

        return substr($raw, 0, 5);
    }
}
