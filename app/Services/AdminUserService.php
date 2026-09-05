<?php

namespace App\Services;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminUserService
{
    public function __construct(private readonly RbacService $rbac) {}

    /**
     * @return list<string>
     */
    public function defaultRelations(): array
    {
        return ['roles.permissions'];
    }

    /**
     * @param  array{search?: string|null, role?: string|null, status?: string|null}  $filters
     * @return Collection<int, User>
     */
    public function list(array $filters = []): Collection
    {
        $query = User::query()
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('slug', RoleSlug::appointableDeskRoleValues()))
            ->with($this->defaultRelations())
            ->orderBy('name');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['role'])) {
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('slug', $filters['role']));
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        return $query->get();
    }

    /**
     * @return Collection<int, Role>
     */
    public function appointableRoles(): Collection
    {
        return Role::query()
            ->whereIn('slug', RoleSlug::appointableDeskRoleValues())
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): User
    {
        $roleSlug = (string) $attributes['role'];
        $this->assertAppointableRole($roleSlug, $actor);

        return DB::transaction(function () use ($attributes, $actor, $roleSlug) {
            $user = User::query()->create([
                'name' => $this->composeName($attributes),
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]);

            $this->rbac->assignUserRoles($user, [$roleSlug], $actor);
            $this->rbac->audit($actor, 'admin.created', $user, [
                'role' => $roleSlug,
                'email' => $user->email,
            ]);

            return $user->fresh($this->defaultRelations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $admin, array $attributes, User $actor): User
    {
        $this->assertManagedDeskUser($admin);
        $this->assertCanActOn($actor, $admin);

        $previousRoles = $admin->roleSlugs()->values()->all();

        return DB::transaction(function () use ($admin, $attributes, $actor, $previousRoles) {
            $login = array_filter([
                'name' => array_key_exists('first_name', $attributes) || array_key_exists('last_name', $attributes) || array_key_exists('name', $attributes)
                    ? $this->composeName($attributes, $admin->name)
                    : null,
                'email' => $attributes['email'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            if ($login !== []) {
                $admin->fill($login)->save();
            }

            if (! empty($attributes['role'])) {
                $roleSlug = (string) $attributes['role'];
                $this->assertAppointableRole($roleSlug, $actor);
                $this->assertRoleChangeAllowed($admin, $roleSlug, $actor);
                $this->rbac->assignUserRoles($admin, [$roleSlug], $actor);
            }

            if (array_key_exists('status', $attributes) && $attributes['status'] !== null) {
                $this->applyStatus($admin, UserStatus::from((string) $attributes['status']), $actor);
            }

            $fresh = $admin->fresh($this->defaultRelations());

            $this->rbac->audit($actor, 'admin.updated', $fresh, [
                'email' => $fresh->email,
                'previous_roles' => $previousRoles,
                'roles' => $fresh->roleSlugs()->values()->all(),
                'status' => $fresh->status?->value,
            ]);

            return $fresh;
        });
    }

    public function changePassword(User $admin, string $password, User $actor): User
    {
        $this->assertManagedDeskUser($admin);
        $this->assertCanActOn($actor, $admin);

        if ($admin->is($actor)) {
            throw ValidationException::withMessages([
                'password' => 'Use My Profile to change your own password.',
            ]);
        }

        $admin->update(['password' => $password]);
        $this->invalidateSessions($admin);

        $this->rbac->audit($actor, 'admin.password_changed', $admin, [
            'email' => $admin->email,
        ]);

        return $admin->fresh($this->defaultRelations());
    }

    public function suspend(User $admin, User $actor): User
    {
        $this->assertManagedDeskUser($admin);
        $this->assertCanActOn($actor, $admin);
        $this->assertNotSelf($actor, $admin, 'suspend');
        $this->assertNotLastActiveSuperAdmin($admin, 'suspend');

        $admin->update(['status' => UserStatus::Suspended]);
        $this->invalidateSessions($admin);

        $this->rbac->audit($actor, 'admin.suspended', $admin, [
            'email' => $admin->email,
        ]);

        return $admin->fresh($this->defaultRelations());
    }

    public function reinstate(User $admin, User $actor): User
    {
        $this->assertManagedDeskUser($admin);
        $this->assertCanActOn($actor, $admin);

        $admin->update(['status' => UserStatus::Active]);

        $this->rbac->audit($actor, 'admin.reactivated', $admin, [
            'email' => $admin->email,
        ]);

        return $admin->fresh($this->defaultRelations());
    }

    public function delete(User $admin, User $actor): void
    {
        $this->assertManagedDeskUser($admin);
        $this->assertCanActOn($actor, $admin);
        $this->assertNotSelf($actor, $admin, 'delete');
        $this->assertNotLastActiveSuperAdmin($admin, 'delete');

        DB::transaction(function () use ($admin, $actor) {
            $this->rbac->audit($actor, 'admin.deleted', $admin, [
                'email' => $admin->email,
                'roles' => $admin->roleSlugs()->values()->all(),
            ]);

            $this->invalidateSessions($admin);
            $admin->roles()->detach();
            $admin->update(['status' => UserStatus::Inactive]);

            $hasPeopleProfile = $admin->staffProfile()->exists()
                || $admin->studentProfile()->exists()
                || $admin->guardianProfile()->exists();

            if (! $hasPeopleProfile) {
                $admin->delete();
            }
        });
    }

    public function assertManagedDeskUser(User $admin): void
    {
        if (! $admin->hasAnyRole(...RoleSlug::appointableDeskRoles())) {
            abort(404);
        }
    }

    public function canManage(User $actor): bool
    {
        return $actor->status === UserStatus::Active
            && (
                $actor->hasRole(RoleSlug::SuperAdmin)
                || $actor->hasAnyPermission(
                    PermissionSlug::AdminsView,
                    PermissionSlug::AdminsCreate,
                    PermissionSlug::AdminsEdit,
                    PermissionSlug::AdminsSuspend,
                    PermissionSlug::AdminsDelete,
                )
            );
    }

    public function actorHas(User $actor, PermissionSlug $permission): bool
    {
        if ($actor->status !== UserStatus::Active) {
            return false;
        }

        return $actor->hasRole(RoleSlug::SuperAdmin) || $actor->hasPermission($permission);
    }

    private function applyStatus(User $admin, UserStatus $status, User $actor): void
    {
        if ($status === UserStatus::Suspended) {
            $this->assertNotSelf($actor, $admin, 'suspend');
            $this->assertNotLastActiveSuperAdmin($admin, 'suspend');
            $admin->update(['status' => UserStatus::Suspended]);
            $this->invalidateSessions($admin);

            return;
        }

        if ($status === UserStatus::Active) {
            $admin->update(['status' => UserStatus::Active]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function composeName(array $attributes, ?string $fallback = null): string
    {
        if (! empty($attributes['name'])) {
            return trim((string) $attributes['name']);
        }

        $first = trim((string) ($attributes['first_name'] ?? ''));
        $last = trim((string) ($attributes['last_name'] ?? ''));
        $composed = trim($first.' '.$last);

        if ($composed !== '') {
            return $composed;
        }

        return (string) $fallback;
    }

    private function assertAppointableRole(string $roleSlug, User $actor): void
    {
        if (! in_array($roleSlug, RoleSlug::appointableDeskRoleValues(), true)) {
            throw ValidationException::withMessages([
                'role' => 'That role cannot be appointed from Admin Users.',
            ]);
        }

        if ($roleSlug === RoleSlug::SuperAdmin->value && ! $actor->hasRole(RoleSlug::SuperAdmin)) {
            throw ValidationException::withMessages([
                'role' => 'Only a super administrator can grant that role.',
            ]);
        }
    }

    private function assertRoleChangeAllowed(User $admin, string $roleSlug, User $actor): void
    {
        if ($admin->is($actor) && $admin->hasRole(RoleSlug::SuperAdmin) && $roleSlug !== RoleSlug::SuperAdmin->value) {
            throw ValidationException::withMessages([
                'role' => 'You cannot remove your own super administrator role here.',
            ]);
        }

        if ($admin->hasRole(RoleSlug::SuperAdmin) && $roleSlug !== RoleSlug::SuperAdmin->value) {
            $this->assertNotLastActiveSuperAdmin($admin, 'demote');
        }
    }

    private function assertCanActOn(User $actor, User $admin): void
    {
        if ($admin->hasRole(RoleSlug::SuperAdmin) && ! $actor->hasRole(RoleSlug::SuperAdmin)) {
            abort(403);
        }
    }

    private function assertNotSelf(User $actor, User $admin, string $action): void
    {
        if ($admin->is($actor)) {
            throw ValidationException::withMessages([
                'admin' => "You cannot {$action} your own account.",
            ]);
        }
    }

    private function assertNotLastActiveSuperAdmin(User $admin, string $action): void
    {
        if (! $admin->hasRole(RoleSlug::SuperAdmin)) {
            return;
        }

        $remaining = User::query()
            ->whereKeyNot($admin->id)
            ->where('status', UserStatus::Active)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('slug', RoleSlug::SuperAdmin->value))
            ->count();

        if ($remaining < 1) {
            throw ValidationException::withMessages([
                'admin' => "The school must keep at least one active super administrator (cannot {$action}).",
            ]);
        }
    }

    private function invalidateSessions(User $user): void
    {
        $user->forceFill([
            'remember_token' => Str::random(60),
        ])->save();

        if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }
    }
}
