<?php

namespace App\Http\Requests\Admissions;

use App\Enums\EnquiryStatus;
use App\Models\ContactEnquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $enquiry = $this->route('contact_enquiry');

        return $enquiry instanceof ContactEnquiry
            && ($this->user()?->can('update', $enquiry) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(EnquiryStatus::class)],
        ];
    }
}
