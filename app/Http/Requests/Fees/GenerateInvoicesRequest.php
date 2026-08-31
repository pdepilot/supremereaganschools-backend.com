<?php

namespace App\Http\Requests\Fees;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;

class GenerateInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invoice::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'term_id' => ['required', 'integer', 'exists:terms,id'],
        ];
    }
}
