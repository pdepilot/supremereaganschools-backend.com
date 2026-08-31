<?php

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\OutboundMail;
use App\Models\User;
use App\Services\PeopleAccessService;

class EmailCenterPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function view(User $user, EmailTemplate|OutboundMail $model): bool
    {
        return $this->access->administers($user);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function update(User $user, EmailTemplate $template): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, EmailTemplate $template): bool
    {
        return $this->access->administers($user) && ! $template->is_system;
    }
}
