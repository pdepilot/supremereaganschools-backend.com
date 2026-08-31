<?php

namespace App\Http\Requests\Admissions;

use App\Enums\ApplicationStatus;
use App\Models\AdmissionApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('admission_application');

        return $application instanceof AdmissionApplication
            && ($this->user()?->can('update', $application) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ApplicationStatus::class)],
        ];
    }
}
