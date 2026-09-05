<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\EmailTemplate;
use App\Models\OutboundMail;
use App\Models\User;
use App\Services\PeopleAccessService;

class EmailCenterPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::EmailView, PermissionSlug::EmailManage);
    }

    public function view(User $user, EmailTemplate|OutboundMail $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::EmailManage);
    }

    public function update(User $user, EmailTemplate $template): bool
    {
        return $this->access->allows($user, PermissionSlug::EmailManage);
    }

    public function delete(User $user, EmailTemplate $template): bool
    {
        return $this->access->allows($user, PermissionSlug::EmailManage) && ! $template->is_system;
    }
}
