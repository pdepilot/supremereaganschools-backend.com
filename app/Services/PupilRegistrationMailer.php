<?php

namespace App\Services;

use App\Mail\SchoolCircularMail;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PupilRegistrationMailer
{
    public function __construct(private readonly EmailCenterService $email) {}

    public function notifyGuardian(StudentProfile $student): bool
    {
        $student->loadMissing(['guardians', 'enrollments.classSectionOffering.classSection.schoolClass']);

        $guardian = $student->guardians->firstWhere('pivot.is_primary', true)
            ?? $student->guardians->first();

        $mailbox = strtolower(trim((string) ($guardian?->email ?? '')));
        if ($guardian === null || $mailbox === '' || ! filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $school = $this->email->schoolContext();
        $pupilName = trim($student->first_name.' '.$student->surname);
        $admission = (string) $student->admission_number;
        $form = $student->enrollments
            ->sortByDesc('id')
            ->first()
            ?->classSectionOffering
            ?->classSection
            ?->schoolClass
            ?->name;
        $parentLogin = url('/parent/login');
        $studentLogin = url('/student/login');
        $phone = trim((string) ($guardian->phone ?? ''));
        $greetingName = trim((string) $guardian->full_name) ?: 'Parent';

        $lines = [
            '<p>'.$pupilName.' has been admitted to '.e($school['name']).'.</p>',
            '<p><strong>Admission number:</strong> '.e($admission).'</p>',
        ];

        if (filled($form)) {
            $lines[] = '<p><strong>Class:</strong> '.e((string) $form).'</p>';
        }

        $lines[] = '<p><strong>How to sign in</strong></p>';
        $lines[] = '<p><strong>Parent desk</strong> (<a href="'.e($parentLogin).'">'.e($parentLogin).'</a>)</p>';
        $lines[] = '<ul>'
            .'<li>Username: this email address ('.e($mailbox).')</li>'
            .'<li>Password: the phone number used during registration'
            .($phone !== '' ? ' (<strong>'.e($phone).'</strong>)' : '')
            .'</li>'
            .'</ul>';
        $lines[] = '<p><strong>Student desk</strong> (<a href="'.e($studentLogin).'">'.e($studentLogin).'</a>)</p>';
        $lines[] = '<ul>'
            .'<li>Username: the admission number (<strong>'.e($admission).'</strong>)</li>'
            .'<li>Password: the same parent phone number used during registration'
            .($phone !== '' ? ' (<strong>'.e($phone).'</strong>)' : '')
            .'</li>'
            .'</ul>';

        $lines[] = '<p>Keep this letter for your records. If anything looks wrong, call the office on '.e((string) ($school['phone'] ?: 'the school line')).'.</p>';

        $text = strip_tags(str_replace(['</p>', '</li>', '<br>', '<br/>'], ["\n\n", "\n", "\n", "\n"], implode('', $lines)));
        $text = html_entity_decode(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text, ENT_QUOTES, 'UTF-8');

        try {
            Mail::to($mailbox, $greetingName)->send(new SchoolCircularMail(
                subjectLine: 'Admission confirmed · '.$admission.' · '.$school['short_name'],
                preheader: $pupilName.' is on the school books. Your family desk sign-in details are inside.',
                headline: 'Your child has been registered',
                greeting: 'Dear '.$greetingName.',',
                bodyHtml: implode('', $lines),
                bodyText: trim($text),
                school: $school,
            ));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
