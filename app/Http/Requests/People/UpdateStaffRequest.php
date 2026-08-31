<?php

namespace App\Http\Requests\People;

use App\Enums\Gender;
use App\Enums\RoleSlug;
use App\Enums\StaffStatus;
use App\Models\StaffProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('staff_profile')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var StaffProfile $staff */
        $staff = $this->route('staff_profile');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staff->user_id)],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', Rule::in([
                RoleSlug::Teacher->value,
                RoleSlug::Staff->value,
                RoleSlug::Principal->value,
                RoleSlug::VicePrincipal->value,
                RoleSlug::Accountant->value,
            ])],
            'staff_number' => ['sometimes', 'string', 'max:50', Rule::unique('staff_profiles', 'staff_number')->ignore($staff)],
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
