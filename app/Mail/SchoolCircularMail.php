<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class SchoolCircularMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $school
     */
    public function __construct(
        public string $subjectLine,
        public string $preheader,
        public string $headline,
        public string $greeting,
        public string $bodyHtml,
        public string $bodyText,
        public array $school,
    ) {}

    public function envelope(): Envelope
    {
        $reply = trim((string) ($this->school['email'] ?? ''));
        $from = (string) config('mail.from.address');

        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $reply !== '' && strcasecmp($reply, $from) !== 0
                ? [new Address($reply, (string) ($this->school['name'] ?? config('app.name')))]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.school-circular',
            text: 'mail.school-circular-text',
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-School-Post' => 'supreme-reagan-schools',
            ],
        );
    }
}
