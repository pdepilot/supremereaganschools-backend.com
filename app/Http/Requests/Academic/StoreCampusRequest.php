<?php

namespace App\Http\Requests\Academic;

use App\Models\Campus;
use Illuminate\Foundation\Http\FormRequest;

class StoreCampusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Campus::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:campuses,name'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
