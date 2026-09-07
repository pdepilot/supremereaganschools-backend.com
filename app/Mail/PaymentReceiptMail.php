<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class PaymentReceiptMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-School-Receipt' => 'supreme-reagan-schools',
        ]);
    }
}
