<?php

namespace App\Http\Requests\Attendance;

use App\Enums\PermissionSlug;
use Illuminate\Foundation\Http\FormRequest;

class AdminManualAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(PermissionSlug::AttendanceManage) ?? false;
    }

    public function rules(): array
    {
        return [
            'staff_profile_id' => ['required', 'integer', 'exists:staff_profiles,id'],
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'date' => ['required', 'date'],
            'clock_in_at' => ['nullable', 'string', 'max:50'],
            'clock_out_at' => ['nullable', 'string', 'max:50'],
            'status' => ['required', 'string', 'in:present,late,early_departure,absent,approved_exception'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}

