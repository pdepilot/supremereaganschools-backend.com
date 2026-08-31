<?php

namespace App\Http\Requests\Academic;

use App\Models\SubjectOffering;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SubjectOffering::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_section_offering_id' => ['required', 'integer', 'exists:class_section_offerings,id'],
            'subject_id' => [
                'required',
                'integer',
                'exists:subjects,id',
                Rule::unique('subject_offerings', 'subject_id')->where('class_section_offering_id', $this->integer('class_section_offering_id')),
            ],
        ];
    }
}
