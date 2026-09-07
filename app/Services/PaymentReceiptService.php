<?php

namespace App\Services;

use App\Enums\FeeChannel;
use App\Enums\PaymentStatus;
use App\Mail\PaymentReceiptMail;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use App\Support\SchoolIdentity;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PaymentReceiptService
{
    public function __construct(private readonly PaymentService $payments) {}

    public function load(Payment $payment): Payment
    {
        return $this->payments->fresh($payment);
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(Payment $payment, bool $forEmail = false): array
    {
        $payment = $payment->relationLoaded('student')
            ? $payment
            : $this->load($payment);

        $invoice = $payment->invoice;
        $paidAt = $payment->paid_at?->timezone('Africa/Lagos');
        $allocations = $payment->allocations
            ->map(fn ($row) => [
                'description' => $row->invoiceItem?->description
                    ?: $row->invoiceItem?->feeType?->name
                    ?: 'School fee',
                'amount' => Money::formatNaira((int) $row->amount_kobo),
            ])
            ->values()
            ->all();

        if ($allocations === []) {
            $allocations = [[
                'description' => $payment->note ?: 'School fee payment',
                'amount' => Money::formatNaira((int) $payment->amount_kobo),
            ]];
        }

        $balance = $invoice?->remainingKobo();

        return [
            'payment' => $payment,
            'forEmail' => $forEmail,
            'school' => [
                'name' => SchoolIdentity::name(),
                'motto' => SchoolIdentity::motto(),
                'address_html' => SchoolIdentity::addressHtml(),
                'phone' => SchoolIdentity::phone(),
                'email' => SchoolIdentity::email(),
                'logo' => SchoolIdentity::logoUrl(),
                'url' => SchoolIdentity::url(),
            ],
            'reference' => (string) $payment->reference,
            'amount' => Money::formatNaira((int) $payment->amount_kobo),
            'amount_words' => $this->amountWords((int) $payment->amount_kobo),
            'channel' => $this->channelLabel($payment->channel),
            'status' => $payment->status?->value === PaymentStatus::Posted->value ? 'Paid' : ucfirst((string) $payment->status?->value),
            'paid_on' => $paidAt?->format('l, j F Y') ?: '—',
            'paid_at' => $paidAt?->format('g:i a') ?: '',
            'student_name' => $payment->student?->fullName() ?: 'Pupil',
            'admission_number' => $payment->student?->admission_number ?: '—',
            'form' => $invoice?->enrollment?->classSectionOffering?->classSection?->name ?: '—',
            'invoice_number' => $invoice?->number ?: '—',
            'term' => trim(implode(' · ', array_filter([
                $invoice?->term?->name,
                $invoice?->academicSession?->name,
            ]))) ?: '—',
            'note' => trim((string) $payment->note),
            'recorded_by' => $payment->recorder?->name ?: 'Fees desk',
            'allocations' => $allocations,
            'balance' => $balance === null ? null : Money::formatNaira((int) $balance),
            'balance_clear' => $balance !== null && (int) $balance <= 0,
            'suggested_email' => $this->suggestedEmail($payment),
            'suggested_name' => $this->suggestedName($payment),
        ];
    }

    public function html(Payment $payment, bool $forEmail = false): string
    {
        return view('fees.payment-receipt', $this->viewData($payment, $forEmail))->render();
    }

    public function send(Payment $payment, User $actor, string $email, ?string $name = null): void
    {
        if ($payment->status !== PaymentStatus::Posted) {
            throw ValidationException::withMessages([
                'payment' => 'Only posted payments can be emailed as a receipt.',
            ]);
        }

        $mailbox = strtolower(trim($email));
        if ($mailbox === '' || ! filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'Enter a valid email address for the receipt.',
            ]);
        }

        $this->assertMailerReady();

        $data = $this->viewData($payment, true);
        $toName = trim((string) ($name ?: $data['suggested_name'] ?: 'Family'));
        $subject = 'Fee receipt '.$data['reference'].' · '.SchoolIdentity::name();

        try {
            Mail::to($mailbox, $toName)->send(new PaymentReceiptMail(
                subjectLine: $subject,
                htmlBody: view('fees.payment-receipt', $data)->render(),
            ));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'mail' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'The school mailbox could not send this receipt.',
            ]);
        }
    }

    public function suggestedEmail(Payment $payment): ?string
    {
        $payment->loadMissing(['student.guardians.user', 'student.user']);

        foreach ($payment->student?->guardians ?? [] as $guardian) {
            $email = strtolower(trim((string) ($guardian->email ?: $guardian->user?->email)));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        $pupil = strtolower(trim((string) ($payment->student?->user?->email ?: '')));
        if ($pupil !== '' && filter_var($pupil, FILTER_VALIDATE_EMAIL) && ! str_contains($pupil, '.invalid')) {
            return $pupil;
        }

        return null;
    }

    public function suggestedName(Payment $payment): string
    {
        $payment->loadMissing('student.guardians');
        $guardian = $payment->student?->guardians?->first();

        if ($guardian) {
            $name = trim((string) ($guardian->full_name ?? $guardian->name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return 'Parent / Guardian';
    }

    private function assertMailerReady(): void
    {
        if (config('mail.default') !== 'smtp') {
            throw ValidationException::withMessages([
                'mail' => 'Set MAIL_MAILER=smtp on the server before emailing receipts.',
            ]);
        }

        if (blank(config('mail.mailers.smtp.password'))) {
            throw ValidationException::withMessages([
                'mail' => 'Set MAIL_PASSWORD in .env to the Hostinger mailbox password, then try again.',
            ]);
        }
    }

    private function channelLabel(?FeeChannel $channel): string
    {
        return match ($channel) {
            FeeChannel::Cash => 'Cash',
            FeeChannel::Transfer => 'Bank transfer',
            FeeChannel::Pos => 'POS',
            FeeChannel::Other => 'Other',
            default => 'Payment',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function plainText(array $data): string
    {
        $lines = [
            $data['school']['name'],
            $data['school']['motto'],
            'Official Fee Receipt',
            'Reference: '.$data['reference'],
            'Amount: '.$data['amount'],
            'Pupil: '.$data['student_name'].' ('.$data['admission_number'].')',
            'Form: '.$data['form'],
            'Paid on: '.$data['paid_on'],
            'Channel: '.$data['channel'],
            'Invoice: '.$data['invoice_number'],
            'Term: '.$data['term'],
        ];

        if (! empty($data['balance'])) {
            $lines[] = $data['balance_clear']
                ? 'Balance: Cleared'
                : 'Balance remaining: '.$data['balance'];
        }

        $lines[] = 'Thank you for trusting '.($data['school']['name']).'.';

        return implode("\n", $lines);
    }

    private function amountWords(int $kobo): string
    {
        $naira = (int) floor(Money::toNaira($kobo));
        if ($naira <= 0) {
            return 'Zero Naira';
        }

        return $this->numberToWords($naira).' Naira only';
    }

    private function numberToWords(int $number): string
    {
        $words = [
            0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
            6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
            11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
            16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty',
            30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy',
            80 => 'Eighty', 90 => 'Ninety',
        ];

        if ($number < 21) {
            return $words[$number];
        }
        if ($number < 100) {
            $tens = ((int) floor($number / 10)) * 10;
            $unit = $number % 10;

            return $unit ? $words[$tens].'-'.strtolower($words[$unit]) : $words[$tens];
        }
        if ($number < 1000) {
            $hundreds = (int) floor($number / 100);
            $rest = $number % 100;

            return $words[$hundreds].' Hundred'.($rest ? ' and '.$this->numberToWords($rest) : '');
        }
        if ($number < 1_000_000) {
            $thousands = (int) floor($number / 1000);
            $rest = $number % 1000;

            return $this->numberToWords($thousands).' Thousand'.($rest ? ($rest < 100 ? ' and ' : ' ').$this->numberToWords($rest) : '');
        }

        $millions = (int) floor($number / 1_000_000);
        $rest = $number % 1_000_000;

        return $this->numberToWords($millions).' Million'.($rest ? ' '.$this->numberToWords($rest) : '');
    }
}
