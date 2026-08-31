<?php

namespace App\Http\Requests\Academic;

use App\Models\AcademicSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromoteAcademicSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_session')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var AcademicSession $target */
        $target = $this->route('academic_session');

        return [
            'source_academic_session_id' => [
                'required',
                'integer',
                Rule::exists('academic_sessions', 'id'),
                Rule::notIn([$target->id]),
            ],
            'copy_teachers' => ['sometimes', 'boolean'],
            'enroll_pupils' => ['sometimes', 'boolean'],
            'only_active_offerings' => ['sometimes', 'boolean'],
        ];
    }
}
