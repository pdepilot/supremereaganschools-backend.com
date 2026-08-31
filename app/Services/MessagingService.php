<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Notifications\SchoolNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessagingService
{
    public function __construct(private readonly PeopleAccessService $access) {}

    /**
     * @return Collection<int, User>
     */
    public function recipients(User $actor): Collection
    {
        return User::query()
            ->where('status', \App\Enums\UserStatus::Active)
            ->where('id', '!=', $actor->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $this->access->canMessage($actor, $user))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function start(array $attributes, User $actor): Conversation
    {
        $recipient = User::query()->find($attributes['recipient_id'] ?? null);
        if ($recipient === null || ! $this->access->canMessage($actor, $recipient)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($attributes, $actor, $recipient) {
            $existing = Conversation::query()
                ->whereHas('participants', fn ($query) => $query->where('users.id', $actor->id))
                ->whereHas('participants', fn ($query) => $query->where('users.id', $recipient->id))
                ->has('participants', '=', 2)
                ->first();

            if ($existing) {
                if (! empty($attributes['subject'])) {
                    $existing->update(['subject' => $attributes['subject']]);
                }

                $this->postMessage($existing, $actor, (string) $attributes['body']);

                return $existing->fresh(['participants', 'messages.sender']);
            }

            $conversation = Conversation::query()->create([
                'subject' => $attributes['subject'],
                'created_by' => $actor->id,
            ]);

            foreach ([$actor->id, $recipient->id] as $userId) {
                ConversationParticipant::query()->create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                    'last_read_at' => $userId === $actor->id ? now() : null,
                ]);
            }

            $this->postMessage($conversation, $actor, (string) $attributes['body']);

            return $conversation->fresh(['participants', 'messages.sender']);
        });
    }

    public function reply(Conversation $conversation, string $body, User $actor): Message
    {
        $this->assertParticipant($conversation, $actor);

        return DB::transaction(function () use ($conversation, $body, $actor) {
            $message = $this->postMessage($conversation, $actor, $body);
            $this->markRead($conversation, $actor);

            return $message;
        });
    }

    public function inbox(User $actor)
    {
        return Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $actor->id))
            ->with(['participants', 'messages' => fn ($query) => $query->latest()->limit(1)])
            ->orderByDesc('updated_at')
            ->get();
    }

    public function open(Conversation $conversation, User $actor): Conversation
    {
        $this->assertParticipant($conversation, $actor);
        $this->markRead($conversation, $actor);

        return $conversation->load(['participants', 'messages.sender']);
    }

    public function unreadCount(Conversation $conversation, User $actor): int
    {
        $pivot = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->first();

        $since = $pivot?->last_read_at;

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->when($since, fn ($query) => $query->where('created_at', '>', $since))
            ->where('sender_id', '!=', $actor->id)
            ->count();
    }

    private function postMessage(Conversation $conversation, User $actor, string $body): Message
    {
        if (blank($body)) {
            throw ValidationException::withMessages([
                'body' => 'A message is required.',
            ]);
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $actor->id,
            'body' => $body,
        ]);

        $conversation->touch();

        $conversation->participants()
            ->where('users.id', '!=', $actor->id)
            ->get()
            ->each(function (User $user) use ($conversation, $body) {
                $user->notify(new SchoolNotice(
                    $conversation->subject,
                    $body,
                    'message',
                    $conversation->id,
                ));
            });

        return $message->load('sender');
    }

    private function markRead(Conversation $conversation, User $actor): void
    {
        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->update(['last_read_at' => now()]);
    }

    private function assertParticipant(Conversation $conversation, User $actor): void
    {
        $isIn = $conversation->participants()->where('users.id', $actor->id)->exists();
        if (! $isIn) {
            throw new AuthorizationException;
        }
    }
}
