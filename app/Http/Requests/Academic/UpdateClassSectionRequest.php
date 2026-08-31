<?php

namespace App\Http\Requests\Academic;

use App\Models\ClassSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('class_section')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ClassSection $section */
        $section = $this->route('class_section');

        return [
            'arm' => ['sometimes', 'string', 'max:5', Rule::unique('class_sections', 'arm')->where('school_class_id', $section->school_class_id)->ignore($section)],
            'name' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('arm')) {
            $this->merge([
                'arm' => strtoupper(trim((string) $this->input('arm'))),
            ]);
        }
    }
}
