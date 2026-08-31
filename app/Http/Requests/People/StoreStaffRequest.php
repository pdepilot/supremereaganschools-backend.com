<?php

namespace App\Http\Requests\People;

use App\Enums\Gender;
use App\Enums\RoleSlug;
use App\Enums\StaffStatus;
use App\Models\StaffProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StaffProfile::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['sometimes', Rule::in([
                RoleSlug::Teacher->value,
                RoleSlug::Staff->value,
                RoleSlug::Principal->value,
                RoleSlug::VicePrincipal->value,
                RoleSlug::Accountant->value,
            ])],
            'staff_number' => ['nullable', 'string', 'max:50', 'unique:staff_profiles,staff_number'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'class_section_offering_id' => [
                'nullable',
                'integer',
                Rule::exists('class_section_offerings', 'id')->where('is_active', true),
            ],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'job_title' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'employed_on' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::enum(StaffStatus::class)],
        ];
    }
}
