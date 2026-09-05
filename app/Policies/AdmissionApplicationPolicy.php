<?php

namespace App\Policies;

use App\Enums\PermissionSlug;
use App\Models\AdmissionApplication;
use App\Models\User;
use App\Services\PeopleAccessService;

class AdmissionApplicationPolicy
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, PermissionSlug::AdmissionsView, PermissionSlug::AdmissionsManage);
    }

    public function view(User $user, AdmissionApplication $application): bool
    {
        return $this->viewAny($user);
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(User $user, AdmissionApplication $application): bool
    {
        return $this->access->allows($user, PermissionSlug::AdmissionsManage);
    }
}
