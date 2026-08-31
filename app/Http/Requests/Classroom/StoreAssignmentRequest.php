<?php

namespace App\Http\Requests\Classroom;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Assignment::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_section_offering_id' => ['required', 'integer', 'exists:class_section_offerings,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'staff_profile_id' => ['nullable', 'integer', 'exists:staff_profiles,id'],
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'due_on' => ['required', 'date'],
        ];
    }
}
