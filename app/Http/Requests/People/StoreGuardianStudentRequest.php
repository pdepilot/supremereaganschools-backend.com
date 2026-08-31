<?php

namespace App\Http\Requests\People;

use App\Enums\GuardianRelationship;
use App\Models\GuardianStudent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuardianStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', GuardianStudent::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_profile_id' => ['required', 'integer', 'exists:student_profiles,id'],
            'relationship' => ['required', Rule::enum(GuardianRelationship::class)],
            'is_primary' => ['sometimes', 'boolean'],
            'can_login' => ['sometimes', 'boolean'],
        ];
    }
}
