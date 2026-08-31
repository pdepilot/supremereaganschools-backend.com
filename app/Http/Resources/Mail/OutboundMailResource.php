<?php

namespace App\Http\Resources\Mail;

use App\Models\OutboundMail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OutboundMail
 */
class OutboundMailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_id' => $this->email_template_id,
            'template_name' => $this->whenLoaded('template', fn () => $this->template?->name),
            'subject' => $this->subject,
            'audience' => $this->audience?->value,
            'body' => $this->body,
            'recipient_count' => $this->recipient_count,
            'sent_count' => $this->sent_count,
            'failed_count' => $this->failed_count,
            'status' => $this->status?->value,
            'error' => $this->error,
            'sent_by' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
