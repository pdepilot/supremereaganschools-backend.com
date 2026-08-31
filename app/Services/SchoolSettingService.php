<?php

namespace App\Services;

use App\Models\SchoolSetting;
use App\Models\Term;
use Illuminate\Validation\ValidationException;

class SchoolSettingService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(SchoolSetting $settings, array $attributes, ?int $updatedBy = null): SchoolSetting
    {
        $sessionId = $attributes['current_academic_session_id'] ?? $settings->current_academic_session_id;
        $termId = $attributes['current_term_id'] ?? $settings->current_term_id;

        if ($termId) {
            $term = Term::query()->find($termId);

            if ($term === null) {
                throw ValidationException::withMessages([
                    'current_term_id' => 'The selected term does not exist.',
                ]);
            }

            if ($sessionId && (int) $term->academic_session_id !== (int) $sessionId) {
                throw ValidationException::withMessages([
                    'current_term_id' => 'The current term must belong to the current academic session.',
                ]);
            }
        }

        $settings->update([
            ...$attributes,
            'updated_by' => $updatedBy,
        ]);

        return $settings->fresh(['currentAcademicSession', 'currentTerm']);
    }
}
