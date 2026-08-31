<?php

namespace App\Http\Requests\Academic;

use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('subject')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Subject $subject */
        $subject = $this->route('subject');

        return [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('subjects', 'name')->ignore($subject)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('subjects', 'code')->ignore($subject)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
