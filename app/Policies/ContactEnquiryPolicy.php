<?php

namespace App\Policies;

use App\Models\ContactEnquiry;
use App\Models\User;
use App\Services\PeopleAccessService;

class ContactEnquiryPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->administers($user);
    }

    public function view(User $user, ContactEnquiry $enquiry): bool
    {
        return $this->access->administers($user);
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(User $user, ContactEnquiry $enquiry): bool
    {
        return $this->access->administers($user);
    }

    public function delete(User $user, ContactEnquiry $enquiry): bool
    {
        return $this->access->administers($user);
    }
}
