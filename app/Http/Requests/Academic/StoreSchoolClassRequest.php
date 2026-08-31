<?php

namespace App\Http\Requests\Academic;

use App\Models\SchoolClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SchoolClass::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'level_id' => ['required', 'integer', 'exists:levels,id'],
            'name' => ['required', 'string', 'max:100', Rule::unique('school_classes', 'name')->where('level_id', $this->integer('level_id'))],
            'short_code' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
