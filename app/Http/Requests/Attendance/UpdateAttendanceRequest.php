<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('attendance_record')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(AttendanceStatus::class)],
            'remark' => ['nullable', 'string', 'max:255'],
            'correction_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
