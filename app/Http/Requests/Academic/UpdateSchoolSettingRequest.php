<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSchoolSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', \App\Models\SchoolSetting::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach ([
            'short_name', 'motto', 'address', 'city', 'state', 'phone', 'email',
            'admissions_email', 'whatsapp', 'website', 'timezone', 'founded_on',
            'office_opens_at', 'office_closes_at', 'logo_path',
            'current_academic_session_id', 'current_term_id',
        ] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);

            if ($value === '') {
                $merge[$field] = null;
                continue;
            }

            if (in_array($field, ['office_opens_at', 'office_closes_at'], true)
                && is_string($value)
                && preg_match('/^(\d{2}:\d{2}):\d{2}$/', $value, $match) === 1) {
                $merge[$field] = $match[1];
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'motto' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'admissions_email' => ['nullable', 'email', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
            'founded_on' => ['nullable', 'date'],
            'office_opens_at' => ['nullable', 'date_format:H:i'],
            'office_closes_at' => ['nullable', 'date_format:H:i'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'current_academic_session_id' => ['nullable', 'integer', 'exists:academic_sessions,id'],
            'current_term_id' => ['nullable', 'integer', 'exists:terms,id'],
        ];
    }
}
