<?php

namespace App\Http\Requests\People;

use App\Models\ClassTeacherAssignment;
use Illuminate\Foundation\Http\FormRequest;

class StoreClassTeacherAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassTeacherAssignment::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staff_profile_id' => ['required', 'integer', 'exists:staff_profiles,id'],
            'class_section_offering_id' => ['required', 'integer', 'exists:class_section_offerings,id'],
            'assigned_on' => ['sometimes', 'date'],
        ];
    }
}
