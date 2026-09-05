<?php

namespace App\Services;

use App\Enums\RoleSlug;
use App\Models\Permission;
use App\Models\RbacAuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RbacService
{
    /**
     * @return Collection<int, Role>
     */
    public function roles(): Collection
    {
        return Role::query()
            ->withCount(['users', 'permissions'])
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Permission>
     */
    public function permissions(): Collection
    {
        return Permission::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRole(array $attributes, User $actor): Role
    {
        $slug = Str::slug((string) ($attributes['slug'] ?? $attributes['name']), '_');

        if (RoleSlug::tryFrom($slug) !== null || Role::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'That role slug is reserved or already in use.',
            ]);
        }

        $role = Role::query()->create([
            'name' => $attributes['name'],
            'slug' => $slug,
            'description' => $attributes['description'] ?? null,
            'is_system_role' => false,
        ]);

        if (! empty($attributes['permissions']) && is_array($attributes['permissions'])) {
            $role->syncPermissions($attributes['permissions']);
        }

        $this->audit($actor, 'role.created', $role, [
            'permissions' => $attributes['permissions'] ?? [],
        ]);

        return $role->fresh(['permissions']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateRole(Role $role, array $attributes, User $actor): Role
    {
        if ((string) $role->slug === RoleSlug::SuperAdmin->value) {
            throw ValidationException::withMessages([
                'role' => 'The super administrator role cannot be edited here.',
            ]);
        }

        $role->fill([
            'name' => $attributes['name'] ?? $role->name,
            'description' => array_key_exists('description', $attributes)
                ? $attributes['description']
                : $role->description,
        ])->save();

        if (array_key_exists('permissions', $attributes)) {
            $role->syncPermissions($attributes['permissions'] ?? []);
        }

        $this->audit($actor, 'role.updated', $role, [
            'permissions' => $attributes['permissions'] ?? null,
        ]);

        return $role->fresh(['permissions']);
    }

    public function deleteRole(Role $role, User $actor): void
    {
        if ($role->is_system_role || (string) $role->slug === RoleSlug::SuperAdmin->value) {
            throw ValidationException::withMessages([
                'role' => 'System roles cannot be deleted.',
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'Remove this role from all users before deleting it.',
            ]);
        }

        $this->audit($actor, 'role.deleted', $role);
        $role->permissions()->detach();
        $role->delete();
    }

    /**
     * @param  list<string>  $permissions
     */
    public function syncRolePermissions(Role $role, array $permissions, User $actor): Role
    {
        if ((string) $role->slug === RoleSlug::SuperAdmin->value) {
            throw ValidationException::withMessages([
                'role' => 'The super administrator role already holds every permission.',
            ]);
        }

        $role->syncPermissions($permissions);
        $this->audit($actor, 'role.permissions_synced', $role, [
            'permissions' => $permissions,
        ]);

        return $role->fresh(['permissions']);
    }

    /**
     * @param  list<string>  $roleSlugs
     */
    public function assignUserRoles(User $target, array $roleSlugs, User $actor): User
    {
        if ($target->hasRole(RoleSlug::SuperAdmin) && ! $actor->hasRole(RoleSlug::SuperAdmin)) {
            throw ValidationException::withMessages([
                'roles' => 'Only a super administrator can change another super administrator.',
            ]);
        }

        $slugs = collect($roleSlugs)->map(fn ($slug) => (string) $slug)->unique()->values();

        if ($slugs->contains(RoleSlug::SuperAdmin->value) && ! $actor->hasRole(RoleSlug::SuperAdmin)) {
            throw ValidationException::withMessages([
                'roles' => 'Only a super administrator can grant that role.',
            ]);
        }

        if ($target->hasRole(RoleSlug::SuperAdmin) && ! $slugs->contains(RoleSlug::SuperAdmin->value)) {
            $remaining = User::query()
                ->whereKeyNot($target->id)
                ->whereHas('roles', fn ($query) => $query->where('slug', RoleSlug::SuperAdmin->value))
                ->count();

            if ($remaining < 1) {
                throw ValidationException::withMessages([
                    'roles' => 'The school must keep at least one super administrator.',
                ]);
            }
        }

        $ids = Role::query()->whereIn('slug', $slugs)->pluck('id')->all();

        if (count($ids) !== $slugs->count()) {
            throw ValidationException::withMessages([
                'roles' => 'One or more roles could not be found.',
            ]);
        }

        $target->roles()->sync($ids);
        $target->unsetRelation('roles');

        $this->audit($actor, 'user.roles_assigned', $target, [
            'roles' => $slugs->all(),
        ]);

        return $target->fresh(['roles.permissions']);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function audit(User $actor, string $action, ?object $subject = null, ?array $meta = null): void
    {
        RbacAuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject->id ?? null,
            'meta' => $meta,
        ]);
    }
}
