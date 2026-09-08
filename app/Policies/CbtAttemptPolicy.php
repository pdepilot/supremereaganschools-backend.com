<?php

namespace App\Policies;

use App\Models\CbtAttempt;
use App\Models\User;
use App\Services\Cbt\CbtAccessService;

class CbtAttemptPolicy
{
    public function __construct(private readonly CbtAccessService $access) {}

    public function view(User $user, CbtAttempt $attempt): bool
    {
        if ($this->access->canManage($user) || $this->access->canMark($user) || $this->access->canProctor($user)) {
            return true;
        }

        return (int) $attempt->user_id === (int) $user->id;
    }

    public function answer(User $user, CbtAttempt $attempt): bool
    {
        return (int) $attempt->user_id === (int) $user->id;
    }

    public function submit(User $user, CbtAttempt $attempt): bool
    {
        return (int) $attempt->user_id === (int) $user->id;
    }
}
