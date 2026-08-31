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
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
