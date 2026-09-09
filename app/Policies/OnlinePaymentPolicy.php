<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\OnlinePayment;
use App\Models\User;
use App\Services\PeopleAccessService;

class OnlinePaymentPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::PaymentsView, PermissionSlug::FeesView);
    }

    public function view(User $user, OnlinePayment $payment): bool
    {
        if ($this->access->allows($user, PermissionSlug::PaymentsView, PermissionSlug::FeesView)) {
            return true;
        }

        return (int) $payment->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user, OnlinePayment $payment): bool
    {
        return false;
    }

    public function delete(User $user, OnlinePayment $payment): bool
    {
        return false;
    }
}
