<?php

namespace App\Http\Requests\Admissions;

use App\Models\ContactEnquiry;
use Illuminate\Foundation\Http\FormRequest;

class StoreContactEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ContactEnquiry::class) ?? true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'intended_level' => ['nullable', 'string', 'max:80'],
            'enquiry_type' => ['nullable', 'string', 'max:80'],
            'source_url' => ['nullable', 'string', 'max:255'],
            'source_post_id' => ['nullable', 'integer', 'exists:posts,id'],
        ];
    }
}
