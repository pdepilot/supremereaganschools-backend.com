<?php

namespace App\Http\Requests\Academic;

use App\Models\Campus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('campus')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Campus $campus */
        $campus = $this->route('campus');

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('campuses', 'name')->ignore($campus)],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('campuses', 'code')->ignore($campus)],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_type' => ['nullable', 'string', 'in:circle,polygon'],
            'radius_meters' => ['nullable', 'integer', 'min:10', 'max:10000'],
            'boundary_coordinates' => ['nullable', 'array'],
            'gps_accuracy_threshold' => ['nullable', 'integer', 'min:5', 'max:500'],
            'operating_hours_start' => ['nullable', 'string'],
            'operating_hours_end' => ['nullable', 'string'],
            'expected_clock_in_time' => ['nullable', 'string'],
            'expected_clock_out_time' => ['nullable', 'string'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'working_days' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
