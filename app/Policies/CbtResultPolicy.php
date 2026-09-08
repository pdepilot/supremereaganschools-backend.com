<?php

namespace App\Policies;

use App\Models\CbtResult;
use App\Models\User;
use App\Services\Cbt\CbtAccessService;

class CbtResultPolicy
{
    public function __construct(private readonly CbtAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canEnter($user) || $this->access->canMark($user);
    }

    public function view(User $user, CbtResult $result): bool
    {
        if ($this->access->canManage($user) || $this->access->canMark($user)) {
            return true;
        }

        $result->loadMissing('attempt');

        return $result->attempt !== null && (int) $result->attempt->user_id === (int) $user->id;
    }

    public function viewAdmin(User $user): bool
    {
        return $this->access->canManage($user) || $this->access->canMark($user);
    }
}
