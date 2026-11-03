<?php

namespace App\Http\Requests\Attendance;

use App\Enums\PermissionSlug;
use Illuminate\Foundation\Http\FormRequest;

class AssignStaffCampusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyPermission(PermissionSlug::StaffEdit, PermissionSlug::AttendanceManage) ?? false;
    }

    public function rules(): array
    {
        return [
            'staff_profile_id' => ['required', 'integer', 'exists:staff_profiles,id'],
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'is_primary' => ['sometimes', 'boolean'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}

