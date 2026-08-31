<?php

namespace App\Http\Resources\Classroom;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actorId = $request->user()?->id;
        $other = $this->whenLoaded('participants', function () use ($actorId) {
            return $this->participants->first(fn ($user) => (int) $user->id !== (int) $actorId)
                ?? $this->participants->first();
        });
        $latest = $this->whenLoaded('messages', fn () => $this->messages->last());

        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'other_user_id' => $other?->id,
            'other_name' => $other?->name,
            'preview' => $latest?->body,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'unread_count' => $this->unread_count ?? 0,
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'participants' => $this->whenLoaded('participants', function () {
                return $this->participants->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                ])->values()->all();
            }),
        ];
    }
}
