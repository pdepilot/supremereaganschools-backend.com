<?php

namespace App\Http\Requests\Fees;

use App\Enums\FeeChannel;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('channel')) {
            $this->merge(['channel' => strtolower((string) $this->input('channel'))]);
        }

        if ($this->exists('reference')) {
            $reference = strtoupper(trim((string) $this->input('reference')));
            $this->merge(['reference' => $reference === '' ? null : $reference]);
        }

        if ($this->filled('amount') && ! $this->filled('amount_kobo')) {
            $this->merge(['amount_kobo' => Money::toKobo($this->input('amount'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'admission_number' => ['required_without:student_profile_id', 'nullable', 'string', 'max:40'],
            'student_profile_id' => ['required_without:admission_number', 'nullable', 'integer', 'exists:student_profiles,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'amount_kobo' => ['required', 'integer', 'min:1'],
            'channel' => ['required', Rule::enum(FeeChannel::class)],
            'reference' => ['nullable', 'string', 'max:40', 'unique:payments,reference'],
            'status' => ['nullable', Rule::in(PaymentStatus::recordableValues())],
            'note' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
