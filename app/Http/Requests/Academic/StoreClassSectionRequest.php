<?php

namespace App\Http\Requests\Academic;

use App\Models\ClassSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassSection::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $classId = $this->route('school_class')?->id;

        return [
            'arm' => ['nullable', 'string', 'max:5', Rule::unique('class_sections', 'arm')->where('school_class_id', $classId)],
            'name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'arm' => strtoupper(trim((string) $this->input('arm', ''))),
        ]);
    }
}
