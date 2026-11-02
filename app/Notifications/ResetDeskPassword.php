<?php

namespace App\Notifications;

use App\Enums\AuthPortal;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetDeskPassword extends Notification
{
    public function __construct(
        public string $token,
        public AuthPortal $portal,
        public ?string $email = null,
        public ?string $admissionNumber = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);
        $recipientEmail = $this->email
            ?? (method_exists($notifiable, 'getEmailForPasswordReset') ? $notifiable->getEmailForPasswordReset() : null)
            ?? ($notifiable->email ?? null)
            ?? ($notifiable->routes['mail'] ?? '');

        if ($this->portal === AuthPortal::Student) {
            $params = ['token' => $this->token];
            if (filled($this->admissionNumber)) {
                $params['admission_number'] = $this->admissionNumber;
            }
            $url = route($this->portal->passwordResetRoute(), $params);
            $subject = filled($this->admissionNumber)
                ? 'Restore pupil passphrase for '.$this->admissionNumber.' at '.config('app.name')
                : 'Restore pupil passphrase at '.config('app.name');
            $greeting = 'Good day.';
            $line = filled($this->admissionNumber)
                ? 'We received a request to restore the pupil passphrase for '.$this->admissionNumber.' at '.config('app.name').'.'
                : 'We received a request to restore a pupil passphrase at '.config('app.name').'.';
        } else {
            $url = route($this->portal->passwordResetRoute(), [
                'token' => $this->token,
                'email' => $recipientEmail,
            ]);
            $subject = 'Restore your '.config('app.name').' password';
            $greeting = 'Good day.';
            $line = 'We received a request to restore the password for '.$this->portal->deskName().' at '.config('app.name').'.';
        }

        return (new MailMessage)
            ->to($recipientEmail)
            ->subject($subject)
            ->greeting($greeting)
            ->line($line)
            ->action('Choose a new password', $url)
            ->line('This letter is valid for '.$minutes.' minutes. If you did not ask for it, you may ignore it and keep your present password.');
    }
}
