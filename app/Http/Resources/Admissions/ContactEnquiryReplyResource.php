<?php

namespace App\Http\Resources\Admissions;

use App\Models\ContactEnquiryReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactEnquiryReply
 */
class ContactEnquiryReplyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'body' => $this->body,
            'author' => $this->whenLoaded('author', fn () => $this->author?->name),
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
