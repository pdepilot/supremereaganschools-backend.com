<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SchoolNotice extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $kind,
        public ?int $relatedId = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'kind' => $this->kind,
            'related_id' => $this->relatedId,
        ];
    }
}
