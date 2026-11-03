<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class StaffAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->staffProfile !== null;
    }

    public function rules(): array
    {
        return [
            'record_id' => ['required', 'integer', 'exists:staff_attendance_records,id'],
            'requested_clock_in' => ['nullable', 'string', 'max:50'],
            'requested_clock_out' => ['nullable', 'string', 'max:50'],
            'requested_status' => ['nullable', 'string', 'in:present,late,early_departure,approved_exception'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}

