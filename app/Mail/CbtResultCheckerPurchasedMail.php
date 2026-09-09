<?php

namespace App\Mail;

use App\Models\CbtResultCheckerPurchase;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CbtResultCheckerPurchasedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly CbtResultCheckerPurchase $purchase) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'CBT Result Checker purchase successful',
        );
    }

    public function content(): Content
    {
        $purchase = $this->purchase->loadMissing(['result.attempt.exam', 'onlinePayment']);

        return new Content(
            htmlString: '<p>Your CBT Result Checker payment was successful.</p>'
                .'<p><strong>Exam:</strong> '.e($purchase->result?->attempt?->exam?->title ?? 'CBT exam').'</p>'
                .'<p><strong>Amount:</strong> '.e(Money::formatNaira((int) $purchase->amount_kobo)).'</p>'
                .'<p><strong>Payment reference:</strong> '.e($purchase->onlinePayment?->reference ?? '—').'</p>'
                .'<p><strong>Result Checker:</strong> '.e($purchase->checker_code).'</p>'
                .'<p><strong>Verification code:</strong> '.e($purchase->verification_code).'</p>'
                .($purchase->expires_at ? '<p><strong>Expires:</strong> '.e($purchase->expires_at->toDayDateTimeString()).'</p>' : '')
                .'<p>Sign in to CBT → Results to view your detailed result.</p>',
        );
    }
}
