<?php

namespace App\Policies;

use App\Models\CbtQuestion;
use App\Models\User;
use App\Services\Cbt\CbtAccessService;

class CbtQuestionPolicy
{
    public function __construct(private readonly CbtAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canManage($user);
    }

    public function view(User $user, CbtQuestion $question): bool
    {
        return $this->access->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->access->canManage($user);
    }

    public function update(User $user, CbtQuestion $question): bool
    {
        return $this->access->canManage($user);
    }

    public function manage(User $user): bool
    {
        return $this->access->canManage($user);
    }
}
