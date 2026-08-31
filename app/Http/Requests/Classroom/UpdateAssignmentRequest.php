<?php

namespace App\Http\Requests\Classroom;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof Assignment
            && ($this->user()?->can('update', $assignment) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'due_on' => ['sometimes', 'date'],
        ];
    }
}
