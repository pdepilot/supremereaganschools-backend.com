<?php

namespace App\Http\Requests\Classroom;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAssignmentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof Assignment
            && ($this->user()?->can('submit', $assignment) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:4000'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpeg,jpg,png'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->file('file') === null && blank($this->input('notes'))) {
                $validator->errors()->add('file', 'Attach a file or write a note to hand in this work.');
            }
        });
    }
}
