<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Services\PeopleAccessService;

class ConversationPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user)
            || $this->access->isTeacher($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->where('users.id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
