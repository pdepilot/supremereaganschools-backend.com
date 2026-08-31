<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AttendanceRecord::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'marked_on' => ['required', 'date'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }
}
