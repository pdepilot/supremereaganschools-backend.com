<?php

namespace App\Http\Requests\Academic;

use App\Models\Level;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('level')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Level $level */
        $level = $this->route('level');

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('levels', 'name')->ignore($level)],
            'slug' => ['sometimes', 'string', 'max:50', 'alpha_dash', Rule::unique('levels', 'slug')->ignore($level)],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
