<?php

namespace App\Http\Resources\Admissions;

use App\Models\ContactEnquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactEnquiry
 */
class ContactEnquiryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
            'intended_level' => $this->intended_level,
            'enquiry_type' => $this->enquiry_type,
            'source_url' => $this->source_url,
            'source_post_id' => $this->source_post_id,
            'status' => $this->status?->value,
            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'replied' => $this->whenLoaded('replies', fn () => $this->replies->isNotEmpty()),
            'replies' => ContactEnquiryReplyResource::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
