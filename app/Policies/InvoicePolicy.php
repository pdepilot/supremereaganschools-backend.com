<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Services\PeopleAccessService;

class InvoicePolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user)
            || $this->access->isParent($user)
            || $this->access->isStudent($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($this->access->administers($user)) {
            return true;
        }

        if ($this->access->isTeacher($user)) {
            return false;
        }

        $invoice->loadMissing('student');
        $student = $invoice->student;

        return $student !== null && $this->access->canViewStudent($user, $student);
    }

    public function create(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->access->administers($user);
    }
}
