<?php

namespace App\Services\Admissions;

use App\Enums\ApplicationStatus;
use App\Enums\OnlinePaymentPurpose;
use App\Mail\AdmissionApplicationReceivedMail;
use App\Models\AdmissionApplication;
use App\Models\OnlinePayment;
use App\Services\ApplicationService;
use App\Services\Payments\Paystack\PaystackPaymentService;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AdmissionCheckoutService
{
    public function __construct(
        private readonly ApplicationService $applications,
        private readonly PaystackPaymentService $payments,
    ) {}

    public function amountKobo(): int
    {
        return max(100, (int) config('services.admission_application_fee.amount_kobo', 500000));
    }

    public function currency(): string
    {
        return strtoupper((string) config(
            'services.admission_application_fee.currency',
            config('services.paystack.currency', 'NGN'),
        ));
    }

    public function amountLabel(): string
    {
        return Money::formatNaira($this->amountKobo());
    }

    /**
     * @return array{amount_kobo: int, amount_label: string, currency: string}
     */
    public function pricing(): array
    {
        return [
            'amount_kobo' => $this->amountKobo(),
            'amount_label' => $this->amountLabel(),
            'currency' => $this->currency(),
        ];
    }

    /**
     * Store the application as pending payment, then open Paystack checkout.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, UploadedFile|null>  $files
     * @return array{application: AdmissionApplication, payment: OnlinePayment, authorization_url: string}
     */
    public function beginCheckout(array $attributes, array $files = []): array
    {
        $application = $this->applications->submitPendingPayment($attributes, $files);

        try {
            $payment = $this->payments->initialize([
                'email' => (string) $application->parent_email,
                'amount_kobo' => $this->amountKobo(),
                'currency' => $this->currency(),
                'purpose' => OnlinePaymentPurpose::AdmissionApplicationFee,
                'callback_url' => route('payments.paystack.callback'),
                'metadata' => [
                    'admission_application_id' => $application->id,
                    'admission_reference' => $application->reference,
                    'applicant_name' => $application->fullName(),
                ],
            ], payable: $application);
        } catch (\Throwable $e) {
            $application->delete();

            throw $e;
        }

        $url = (string) $payment->authorization_url;
        if ($url === '') {
            $application->delete();
            $payment->update([
                'status' => \App\Enums\OnlinePaymentStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'Missing Paystack authorization URL.',
            ]);

            throw ValidationException::withMessages([
                'payment' => 'Unable to start payment. Please try again.',
            ]);
        }

        return [
            'application' => $application->fresh(['documents', 'level', 'academicSession']) ?? $application,
            'payment' => $payment,
            'authorization_url' => $url,
        ];
    }

    public function finalizeFromPayment(OnlinePayment $payment): ?AdmissionApplication
    {
        if ($payment->purpose !== OnlinePaymentPurpose::AdmissionApplicationFee || ! $payment->isPaid()) {
            return null;
        }

        $application = $payment->payable;
        if (! $application instanceof AdmissionApplication) {
            $applicationId = (int) ($payment->metadata['admission_application_id'] ?? 0);
            $application = $applicationId > 0
                ? AdmissionApplication::query()->find($applicationId)
                : null;
        }

        if ($application === null) {
            Log::warning('admission.payment_missing_application', [
                'payment_reference' => $payment->reference,
            ]);

            return null;
        }

        if ($application->status === ApplicationStatus::PendingPayment) {
            $application->update(['status' => ApplicationStatus::Submitted]);
            $application = $application->fresh(['documents', 'level', 'academicSession']) ?? $application;
            $this->sendReceivedMail($application);
        }

        return $application;
    }

    private function sendReceivedMail(AdmissionApplication $application): void
    {
        $email = strtolower(trim((string) $application->parent_email));
        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new AdmissionApplicationReceivedMail($application));
        } catch (\Throwable $e) {
            report($e);
            Log::warning('admission.confirmation_mail_failed', [
                'reference' => $application->reference,
            ]);
        }
    }
}
