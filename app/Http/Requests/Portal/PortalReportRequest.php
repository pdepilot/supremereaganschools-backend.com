<?php

namespace App\Http\Requests\Portal;

use App\Models\SchoolSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortalReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', SchoolSetting::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['roll', 'fees', 'attendance', 'staff'])],
            'academic_session_id' => ['nullable', 'integer', 'exists:academic_sessions,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'class_section_offering_id' => ['nullable', 'integer', 'exists:class_section_offerings,id'],
            'status' => ['nullable', 'string', Rule::in([
                'paid',
                'partial',
                'unpaid',
                'paid_in_full',
                'partially_paid',
                'outstanding',
            ])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
