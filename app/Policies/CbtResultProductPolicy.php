<?php

namespace App\Policies;

use App\Models\CbtResultProduct;
use App\Models\User;
use App\Services\Cbt\CbtAccessService;

class CbtResultProductPolicy
{
    public function __construct(private readonly CbtAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canManage($user);
    }

    public function manage(User $user, ?CbtResultProduct $product = null): bool
    {
        return $this->access->canManage($user);
    }
}
