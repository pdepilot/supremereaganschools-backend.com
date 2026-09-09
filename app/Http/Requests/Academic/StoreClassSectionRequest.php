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
            'arm' => ['nullable', 'string', 'max:40', Rule::unique('class_sections', 'arm')->where('school_class_id', $classId)],
            'name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $arm = trim((string) $this->input('arm', ''));

        $this->merge([
            // Keep short letter arms uppercase (A/B); preserve named arms like "Blossom".
            'arm' => strlen($arm) <= 2 ? strtoupper($arm) : $arm,
        ]);
    }
}
