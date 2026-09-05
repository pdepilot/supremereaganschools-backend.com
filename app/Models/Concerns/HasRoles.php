<?php

namespace App\Models\Concerns;

use App\Enums\RoleSlug;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * @mixin Model
 *
 * @property-read Collection<int, Role> $roles
 */
trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function assignRole(RoleSlug|Role|string $role): void
    {
        $roleId = $this->resolveRoleId($role);

        $this->roles()->syncWithoutDetaching([$roleId]);
        $this->unsetRelation('roles');
    }

    public function hasRole(RoleSlug|Role|string $role): bool
    {
        $slug = $this->resolveRoleSlugValue($role);

        return $this->roleSlugs()->contains($slug);
    }

    public function hasAnyRole(RoleSlug|Role|string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, string>
     */
    public function roleSlugs(): Collection
    {
        return $this->roles->map(
            fn (Role $role) => $role->slug instanceof RoleSlug ? $role->slug->value : (string) $role->slug
        );
    }

    private function resolveRoleId(RoleSlug|Role|string $role): int
    {
        if ($role instanceof Role) {
            return (int) $role->id;
        }

        $slug = $this->resolveRoleSlugValue($role);

        return (int) Role::query()->where('slug', $slug)->firstOrFail()->id;
    }

    private function resolveRoleSlugValue(RoleSlug|Role|string $role): string
    {
        if ($role instanceof RoleSlug) {
            return $role->value;
        }

        if ($role instanceof Role) {
            return $role->slug instanceof RoleSlug
                ? $role->slug->value
                : (string) $role->slug;
        }

        return (string) $role;
    }
}
