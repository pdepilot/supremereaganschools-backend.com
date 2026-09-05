<?php

namespace App\Models\Concerns;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * @mixin Model
 */
trait HasPermissions
{
    private static ?bool $permissionTablesReady = null;

    public static function permissionTablesReady(): bool
    {
        return self::$permissionTablesReady ??= Schema::hasTable('permissions')
            && Schema::hasTable('permission_role');
    }

    /**
     * @return Collection<int, string>
     */
    public function permissionSlugs(): Collection
    {
        if ($this->hasRole(RoleSlug::SuperAdmin)) {
            return collect(PermissionSlug::cases())->map(fn (PermissionSlug $slug) => $slug->value);
        }

        if (! self::permissionTablesReady()) {
            return $this->hasRole(RoleSlug::SchoolAdmin)
                ? collect(PermissionSlug::cases())->map(fn (PermissionSlug $slug) => $slug->value)
                : collect();
        }

        $this->loadMissing('roles.permissions');

        $assigned = $this->roles
            ->flatMap(fn (Role $role) => $role->permissions)
            ->map(fn (Permission $permission) => $permission->slug instanceof PermissionSlug
                ? $permission->slug->value
                : (string) $permission->slug)
            ->unique()
            ->values();

        if ($assigned->isEmpty() && $this->hasRole(RoleSlug::SchoolAdmin)) {
            return collect(PermissionSlug::cases())->map(fn (PermissionSlug $slug) => $slug->value);
        }

        return $assigned;
    }

    public function hasPermission(PermissionSlug|Permission|string $permission): bool
    {
        if ($this->hasRole(RoleSlug::SuperAdmin)) {
            return true;
        }

        $slug = $this->resolvePermissionSlug($permission);

        return $this->permissionSlugs()->contains($slug->value);
    }

    public function hasAnyPermission(PermissionSlug|Permission|string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(PermissionSlug|Permission|string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }

        return $permissions !== [];
    }

    public function canAccessDeskPage(string $page): bool
    {
        if ($this->hasRole(RoleSlug::SuperAdmin) || $this->hasPermission(PermissionSlug::DeskAdminister)) {
            return true;
        }

        foreach (PermissionSlug::cases() as $permission) {
            if (! in_array($page, $permission->pages(), true)) {
                continue;
            }

            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    private function resolvePermissionSlug(PermissionSlug|Permission|string $permission): PermissionSlug
    {
        if ($permission instanceof PermissionSlug) {
            return $permission;
        }

        if ($permission instanceof Permission) {
            return $permission->slug instanceof PermissionSlug
                ? $permission->slug
                : PermissionSlug::from((string) $permission->slug);
        }

        return PermissionSlug::from($permission);
    }
}
