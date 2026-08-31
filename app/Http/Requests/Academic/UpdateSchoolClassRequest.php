<?php

namespace App\Http\Requests\Academic;

use App\Models\SchoolClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('school_class')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var SchoolClass $class */
        $class = $this->route('school_class');
        $levelId = $this->integer('level_id') ?: $class->level_id;

        return [
            'level_id' => ['sometimes', 'integer', 'exists:levels,id'],
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('school_classes', 'name')->where('level_id', $levelId)->ignore($class)],
            'short_code' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
