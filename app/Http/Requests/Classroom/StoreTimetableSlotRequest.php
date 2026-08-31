<?php

namespace App\Http\Requests\Classroom;

use App\Models\TimetableSlot;
use Illuminate\Foundation\Http\FormRequest;

class StoreTimetableSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TimetableSlot::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_section_offering_id' => ['required', 'integer', 'exists:class_section_offerings,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:5'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id', 'required_without:label'],
            'staff_profile_id' => ['nullable', 'integer', 'exists:staff_profiles,id'],
            'label' => ['nullable', 'string', 'max:255', 'required_without:subject_id'],
        ];
    }
}
