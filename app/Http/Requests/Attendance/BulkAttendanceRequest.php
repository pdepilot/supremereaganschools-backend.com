<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAttendanceRequest extends FormRequest
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
            'class_section_offering_id' => ['required', 'integer', 'exists:class_section_offerings,id'],
            'marked_on' => ['required', 'date'],
            'correction_reason' => ['nullable', 'string', 'max:255'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.enrollment_id' => ['required', 'integer', 'exists:enrollments,id', 'distinct'],
            'records.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'records.*.remark' => ['nullable', 'string', 'max:255'],
        ];
    }
}
