<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Services\PeopleAccessService;

class PaymentPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($this->access->administers($user)) {
            return true;
        }

        if ($this->access->isTeacher($user)) {
            return false;
        }

        $payment->loadMissing('student');
        $student = $payment->student;

        return $student !== null && $this->access->canViewStudent($user, $student);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
