<?php

namespace App\Http\Requests\Classroom;

use App\Models\LearningMaterial;
use Illuminate\Foundation\Http\FormRequest;

class StoreLearningMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LearningMaterial::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_section_offering_id' => ['required', 'integer', 'exists:class_section_offerings,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,ppt,pptx,mp4,jpeg,jpg,png'],
        ];
    }
}
