<?php

namespace App\Http\Requests\Classroom;

use App\Models\TimetableSlot;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTimetableSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $slot = $this->route('timetable_slot') ?? $this->route('timetableSlot');

        return $slot instanceof TimetableSlot
            && ($this->user()?->can('update', $slot) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_section_offering_id' => ['sometimes', 'integer', 'exists:class_section_offerings,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'day_of_week' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'starts_at' => ['sometimes', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'date_format:H:i'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'staff_profile_id' => ['nullable', 'integer', 'exists:staff_profiles,id'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
