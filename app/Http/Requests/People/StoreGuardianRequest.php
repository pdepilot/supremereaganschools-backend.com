<?php

namespace App\Http\Requests\People;

use App\Enums\GuardianRelationship;
use App\Models\GuardianProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', GuardianProfile::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'email' => array_filter([
                'nullable',
                'email',
                'max:255',
                $this->filled('password') ? 'required' : null,
                $this->filled('password') ? Rule::unique('users', 'email') : null,
            ]),
            'password' => ['nullable', 'string', 'min:8'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'student_profile_id' => ['nullable', 'integer', 'exists:student_profiles,id'],
            'relationship' => ['sometimes', Rule::enum(GuardianRelationship::class)],
            'is_primary' => ['sometimes', 'boolean'],
            'can_login' => ['sometimes', 'boolean'],
        ];
    }
}
