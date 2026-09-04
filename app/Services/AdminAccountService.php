<?php

namespace App\Services;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminAccountService
{
    /**
     * @return list<string>
     */
    public function defaultRelations(): array
    {
        return ['roles', 'permissions'];
    }

    /**
     * @return Collection<int, User>
     */
    public function list(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('slug', [
                RoleSlug::SuperAdmin->value,
                RoleSlug::SchoolAdmin->value,
            ]))
            ->with($this->defaultRelations())
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Permission>
     */
    public function permissionCatalogue(): Collection
    {
        return Permission::query()
            ->whereIn('slug', collect(PermissionSlug::assignable())->map->value)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): User
    {
        $this->assertSuperAdmin($actor);

        return DB::transaction(function () use ($attributes) {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]);

            $user->roles()->sync([
                Role::query()->where('slug', RoleSlug::SchoolAdmin->value)->firstOrFail()->id,
            ]);
            $user->unsetRelation('roles');

            $user->syncPermissions($attributes['permissions'] ?? []);

            return $user->fresh($this->defaultRelations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $admin, array $attributes, User $actor): User
    {
        $this->assertManagedAdmin($admin);

        $wantsPermissions = array_key_exists('permissions', $attributes);
        $wantsStatus = array_key_exists('status', $attributes);
        $wantsLogin = collect($attributes)->only(['name', 'email', 'password'])->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();

        if ($wantsPermissions || $wantsStatus) {
            $this->assertSuperAdmin($actor);
        }

        if ($wantsLogin) {
            $this->assertCanChangeLogin($actor, $admin);
        }

        if (! $wantsPermissions && ! $wantsStatus && ! $wantsLogin) {
            throw ValidationException::withMessages([
                'name' => 'Nothing to update on this admin account.',
            ]);
        }

        return DB::transaction(function () use ($admin, $attributes, $wantsPermissions, $wantsStatus) {
            $login = array_filter([
                'name' => $attributes['name'] ?? null,
                'email' => $attributes['email'] ?? null,
                'password' => $attributes['password'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            if ($login !== []) {
                $admin->fill($login)->save();
            }

            if ($wantsStatus) {
                $admin->update(['status' => $attributes['status']]);
            }

            if ($wantsPermissions) {
                if ($admin->hasRole(RoleSlug::SuperAdmin)) {
                    throw ValidationException::withMessages([
                        'permissions' => 'Super admin already holds every desk permission.',
                    ]);
                }

                $admin->syncPermissions($attributes['permissions'] ?? []);
            }

            return $admin->fresh($this->defaultRelations());
        });
    }

    public function suspend(User $admin, User $actor): User
    {
        $this->assertSuperAdmin($actor);
        $this->assertManagedAdmin($admin);
        $this->assertNotSelf($actor, $admin);

        $admin->update(['status' => UserStatus::Suspended]);

        return $admin->fresh($this->defaultRelations());
    }

    public function reinstate(User $admin, User $actor): User
    {
        $this->assertSuperAdmin($actor);
        $this->assertManagedAdmin($admin);

        $admin->update(['status' => UserStatus::Active]);

        return $admin->fresh($this->defaultRelations());
    }

    public function delete(User $admin, User $actor): void
    {
        $this->assertSuperAdmin($actor);
        $this->assertManagedAdmin($admin);
        $this->assertNotSelf($actor, $admin);

        if ($admin->hasRole(RoleSlug::SuperAdmin)) {
            throw ValidationException::withMessages([
                'admin' => 'A super admin account cannot be removed from here.',
            ]);
        }

        DB::transaction(function () use ($admin) {
            $admin->permissions()->detach();
            $admin->roles()->detach();
            $admin->update(['status' => UserStatus::Inactive]);
            $admin->delete();
        });
    }

    public function assertCanManageAccounts(User $actor): void
    {
        if (! app(PeopleAccessService::class)->administers($actor)) {
            abort(403);
        }
    }

    public function assertSuperAdmin(User $actor): void
    {
        if (! $actor->hasRole(RoleSlug::SuperAdmin) || $actor->status !== UserStatus::Active) {
            abort(403);
        }
    }

    public function assertCanChangeLogin(User $actor, User $admin): void
    {
        if (! app(PeopleAccessService::class)->administers($actor)) {
            abort(403);
        }

        if ($admin->hasRole(RoleSlug::SuperAdmin) && ! $actor->hasRole(RoleSlug::SuperAdmin)) {
            abort(403);
        }
    }

    private function assertManagedAdmin(User $admin): void
    {
        if (! $admin->hasAnyRole(RoleSlug::SuperAdmin, RoleSlug::SchoolAdmin)) {
            throw ValidationException::withMessages([
                'admin' => 'That account is not a portal admin.',
            ]);
        }
    }

    private function assertNotSelf(User $actor, User $admin): void
    {
        if ($actor->id === $admin->id) {
            throw ValidationException::withMessages([
                'admin' => 'You cannot suspend or remove your own desk account here.',
            ]);
        }
    }
}
