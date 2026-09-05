<?php

namespace App\Models\Concerns;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * @mixin Model
 *
 * @property-read Collection<int, Permission> $permissions
 */
trait HasPermissions
{
    private static ?bool $permissionsTablesReady = null;

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    public static function permissionsTablesReady(): bool
    {
        return self::$permissionsTablesReady ??= Schema::hasTable('permissions')
            && Schema::hasTable('permission_user');
    }

    /**
     * @param  list<PermissionSlug|Permission|string>  $permissions
     */
    public function syncPermissions(array $permissions): void
    {
        if (! self::permissionsTablesReady()) {
            return;
        }

        $ids = collect($permissions)
            ->map(fn ($permission) => $this->resolvePermissionId($permission))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->permissions()->sync($ids);
        $this->unsetRelation('permissions');
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

    /**
     * @return Collection<int, string>
     */
    public function permissionSlugs(): Collection
    {
        if ($this->hasRole(RoleSlug::SuperAdmin)) {
            return collect(PermissionSlug::cases())->map(fn (PermissionSlug $slug) => $slug->value);
        }

        // Before the permissions migration has run on a host, keep the full school desk open.
        if (! self::permissionsTablesReady()) {
            return $this->hasRole(RoleSlug::SchoolAdmin)
                ? collect(PermissionSlug::assignable())->map(fn (PermissionSlug $slug) => $slug->value)
                : collect();
        }

        $assigned = $this->permissions->map(
            fn (Permission $permission) => $permission->slug instanceof PermissionSlug
                ? $permission->slug->value
                : (string) $permission->slug
        )->values();

        if ($assigned->isEmpty() && $this->hasRole(RoleSlug::SchoolAdmin)) {
            return collect(PermissionSlug::assignable())->map(fn (PermissionSlug $slug) => $slug->value);
        }

        return $assigned;
    }

    public function canAccessDeskPage(string $page): bool
    {
        if ($this->hasRole(RoleSlug::SuperAdmin)) {
            return true;
        }

        foreach (PermissionSlug::cases() as $permission) {
            if (! in_array($page, $permission->pages(), true)) {
                continue;
            }

            return $this->hasPermission($permission);
        }

        return false;
    }

    private function resolvePermissionId(PermissionSlug|Permission|string $permission): int
    {
        if ($permission instanceof Permission) {
            return (int) $permission->id;
        }

        $slug = $this->resolvePermissionSlug($permission);

        return (int) Permission::query()->where('slug', $slug->value)->firstOrFail()->id;
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
