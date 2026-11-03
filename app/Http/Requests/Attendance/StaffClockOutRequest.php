<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class StaffClockOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->staffProfile !== null;
    }

    public function rules(): array
    {
        return [
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0'],
            'device' => ['nullable', 'string', 'max:255'],
        ];
    }
}

