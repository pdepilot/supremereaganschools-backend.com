<?php

namespace App\Http\Requests\Academic;

use App\Models\Level;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Level::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:levels,name'],
            'slug' => ['required', 'string', 'max:50', 'unique:levels,slug', 'alpha_dash'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
