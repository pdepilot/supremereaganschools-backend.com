<?php

namespace App\Mail;

use App\Models\AdmissionApplication;
use App\Models\SchoolSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdmissionApplicationReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly AdmissionApplication $application) {}

    public function envelope(): Envelope
    {
        $school = SchoolSetting::query()->value('name') ?: 'Supreme Reagan Schools';

        return new Envelope(
            subject: 'Application received — '.$school,
        );
    }

    public function content(): Content
    {
        $application = $this->application;
        $school = SchoolSetting::query()->first();
        $schoolName = $school?->name ?: 'Supreme Reagan Schools';
        $phone = $school?->phone ?: '09065641343';
        $email = $school?->admissions_email ?: ($school?->email ?: 'supremereagansch@gmail.com');

        return new Content(
            htmlString: '<p>Dear '.e($application->parent_name).',</p>'
                .'<p>Thank you. We have received the admission application for '
                .'<strong>'.e($application->fullName()).'</strong>.</p>'
                .'<p>Your application is being reviewed by the admissions office.</p>'
                .'<p><strong>Application reference:</strong> '.e($application->reference).'</p>'
                .'<p><strong>Class applied:</strong> '.e($application->class_applied).'</p>'
                .'<p><strong>Session:</strong> '.e($application->session_name).'</p>'
                .'<p>Please keep this reference. We will contact you with the next steps for the entry examination.</p>'
                .'<p>Admissions enquiries: '.e($phone).' · '.e($email).'</p>'
                .'<p>'.$schoolName.'</p>',
        );
    }
}
