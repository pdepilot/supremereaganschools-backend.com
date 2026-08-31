<?php

namespace App\Http\Requests\People;

use App\Models\SubjectTeacherAssignment;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubjectTeacherAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SubjectTeacherAssignment::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staff_profile_id' => ['required', 'integer', 'exists:staff_profiles,id'],
            'subject_offering_id' => ['required', 'integer', 'exists:subject_offerings,id'],
            'assigned_on' => ['sometimes', 'date'],
        ];
    }
}
