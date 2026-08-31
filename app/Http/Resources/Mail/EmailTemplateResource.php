<?php

namespace App\Http\Resources\Mail;

use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailTemplate
 */
class EmailTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'audience' => $this->audience?->value,
            'subject' => $this->subject,
            'preheader' => $this->preheader,
            'body' => $this->body,
            'is_system' => $this->is_system,
        ];
    }
}
