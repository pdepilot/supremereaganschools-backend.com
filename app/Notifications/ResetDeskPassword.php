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
        $url = url(route($this->portal->passwordResetRoute(), [
            'token' => $this->token,
        ])).'?email='.urlencode((string) $notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Restore your '.config('app.name').' password')
            ->greeting('Good day.')
            ->line('We received a request to restore the password for '.$this->portal->deskName().' at '.config('app.name').'.')
            ->action('Choose a new password', $url)
            ->line('This letter is valid for '.$minutes.' minutes. If you did not ask for it, you may ignore it and keep your present password.');
    }
}
