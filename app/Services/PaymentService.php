<?php

namespace App\Services;

use App\Enums\FeeChannel;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly SchoolNumberService $numbers,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function post(array $attributes, User $actor): Payment
    {
        $student = $this->studentFrom($attributes);
        $amountKobo = $this->amountKobo($attributes);
        $invoice = $this->invoiceFor($student, $attributes['invoice_id'] ?? null);
        $status = PaymentStatus::tryFrom((string) ($attributes['status'] ?? '')) ?? PaymentStatus::Posted;

        if (! in_array($status, [PaymentStatus::Posted, PaymentStatus::Pending, PaymentStatus::Failed], true)) {
            throw ValidationException::withMessages([
                'status' => 'A payment cannot be recorded with that status.',
            ]);
        }

        if ($status === PaymentStatus::Posted && $amountKobo > $invoice->remainingKobo()) {
            throw ValidationException::withMessages([
                'amount' => 'That amount is more than the outstanding balance of ₦'
                    .number_format(Money::toNaira($invoice->remainingKobo()), 0).'.',
            ]);
        }

        return DB::transaction(function () use ($student, $invoice, $amountKobo, $attributes, $actor, $status) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $locked->load('items');

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'This invoice is not open for payment.',
                ]);
            }

            if ($status === PaymentStatus::Posted && $amountKobo > $locked->remainingKobo()) {
                throw ValidationException::withMessages([
                    'amount' => 'That amount is more than the outstanding balance.',
                ]);
            }

            $reference = $this->uniqueReference($attributes['reference'] ?? null);

            $payment = Payment::query()->create([
                'reference' => $reference,
                'student_profile_id' => $student->id,
                'invoice_id' => $locked->id,
                'amount_kobo' => $amountKobo,
                'channel' => $attributes['channel'],
                'note' => $attributes['note'] ?? null,
                'paid_at' => $attributes['paid_at'] ?? Carbon::now('Africa/Lagos'),
                'status' => $status,
                'recorded_by' => $actor->id,
            ]);

            if ($status !== PaymentStatus::Posted) {
                return $this->fresh($payment);
            }

            $remaining = $amountKobo;

            foreach ($locked->items as $item) {
                if ($remaining <= 0) {
                    break;
                }

                $due = $item->remainingKobo();
                if ($due <= 0) {
                    continue;
                }

                $share = min($due, $remaining);
                $payment->allocations()->create([
                    'invoice_item_id' => $item->id,
                    'amount_kobo' => $share,
                ]);
                $remaining -= $share;
            }

            if ($remaining > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'The payment could not be allocated across the invoice lines.',
                ]);
            }

            $this->invoices->refreshTotals($locked);

            return $this->fresh($payment);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function void(Payment $payment, array $attributes, User $actor): Payment
    {
        if ($payment->status === PaymentStatus::Void) {
            throw ValidationException::withMessages([
                'payment' => 'This payment has already been voided.',
            ]);
        }

        if (blank($attributes['void_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'void_reason' => 'A reason is required to void a payment.',
            ]);
        }

        return DB::transaction(function () use ($payment, $attributes, $actor) {
            $payment->update([
                'status' => PaymentStatus::Void,
                'voided_by' => $actor->id,
                'voided_at' => Carbon::now('Africa/Lagos'),
                'void_reason' => $attributes['void_reason'],
            ]);

            if ($payment->invoice_id) {
                $this->invoices->refreshTotals(Invoice::query()->findOrFail($payment->invoice_id));
            }

            return $this->fresh($payment);
        });
    }

    /**
     * @return list<string>
     */
    public function defaultRelations(): array
    {
        return [
            'student',
            'invoice.term',
            'invoice.academicSession',
            'invoice.enrollment.classSectionOffering.classSection',
            'recorder:id,name',
            'voider:id,name',
            'allocations.invoiceItem.feeType',
        ];
    }

    public function fresh(Payment $payment): Payment
    {
        return $payment->fresh($this->defaultRelations());
    }

    private function uniqueReference(mixed $provided): string
    {
        $reference = strtoupper(trim((string) $provided));

        if ($reference === '') {
            return $this->numbers->nextPaymentReference();
        }

        if (Payment::query()->where('reference', $reference)->exists()) {
            throw ValidationException::withMessages([
                'reference' => 'That payment reference has already been used.',
            ]);
        }

        return $reference;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function studentFrom(array $attributes): StudentProfile
    {
        if (! empty($attributes['admission_number'])) {
            $student = StudentProfile::query()
                ->where('admission_number', trim((string) $attributes['admission_number']))
                ->first();

            if ($student === null) {
                throw ValidationException::withMessages([
                    'admission_number' => 'No pupil matches that admission number.',
                ]);
            }

            return $student;
        }

        $student = StudentProfile::query()->find($attributes['student_profile_id'] ?? null);

        if ($student === null) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'The selected pupil does not exist.',
            ]);
        }

        return $student;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function amountKobo(array $attributes): int
    {
        if (isset($attributes['amount_kobo'])) {
            $kobo = (int) $attributes['amount_kobo'];
        } else {
            $kobo = Money::toKobo($attributes['amount'] ?? 0);
        }

        if ($kobo < 1) {
            throw ValidationException::withMessages([
                'amount' => 'The payment amount must be greater than zero.',
            ]);
        }

        return $kobo;
    }

    private function invoiceFor(StudentProfile $student, mixed $invoiceId): Invoice
    {
        if ($invoiceId) {
            $invoice = Invoice::query()->with('items')->find($invoiceId);

            if ($invoice === null || (int) $invoice->student_profile_id !== (int) $student->id) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'That invoice does not belong to this pupil.',
                ]);
            }
        } else {
            $invoice = Invoice::query()
                ->with('items')
                ->where('student_profile_id', $student->id)
                ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Partial->value])
                ->orderByDesc('id')
                ->first();
        }

        if ($invoice === null) {
            throw ValidationException::withMessages([
                'admission_number' => 'This pupil has no open invoice to receive a payment.',
            ]);
        }

        if (! $invoice->isOpen()) {
            throw ValidationException::withMessages([
                'invoice_id' => 'This invoice is not open for payment.',
            ]);
        }

        return $invoice;
    }
}
