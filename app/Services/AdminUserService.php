<?php

namespace App\Services;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Http\Resources\AdminUserResource;
use App\Models\LoginActivity;
use App\Models\Permission;
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
            ->where(function (Builder $outer) {
                $outer->whereHas(
                    'roles',
                    fn (Builder $roles) => $roles->whereIn('slug', RoleSlug::appointableDeskRoleValues())
                )->orWhereHas(
                    'roles',
                    fn (Builder $roles) => $roles->where('slug', 'like', 'desk_u_%')
                );
            })
            ->with($this->defaultRelations())
            ->orderBy('name');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['role'])) {
            $role = (string) $filters['role'];
            $query->where(function (Builder $outer) use ($role) {
                $outer->whereHas('roles', fn (Builder $roles) => $roles->where('slug', $role))
                    ->orWhereHas(
                        'roles',
                        fn (Builder $roles) => $roles
                            ->where('slug', 'like', 'desk_u_%')
                            ->where('description', 'like', 'template:'.$role.'%')
                    );
            });
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
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }

    /**
     * Permission catalogue for the Admin Users appoint form.
     *
     * @return Collection<int, Permission>
     */
    public function appointablePermissions(): Collection
    {
        return Permission::query()
            ->whereNotIn('slug', array_map(
                fn (PermissionSlug $slug) => $slug->value,
                array_values(array_filter(
                    PermissionSlug::cases(),
                    fn (PermissionSlug $slug) => $slug->isSuperAdminOnly()
                ))
            ))
            ->orderBy('sort_order')
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

            $permissions = array_key_exists('permissions', $attributes) && is_array($attributes['permissions'])
                ? array_values($attributes['permissions'])
                : null;

            $this->applyDeskAccess($user, $roleSlug, $permissions, $actor);

            $this->rbac->audit($actor, 'admin.created', $user, [
                'role' => $roleSlug,
                'email' => $user->email,
                'permissions' => $permissions,
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

        $admin->loadMissing('roles');
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

            $roleSlug = ! empty($attributes['role']) ? (string) $attributes['role'] : $this->templateRoleSlug($admin);
            $permissions = array_key_exists('permissions', $attributes) && is_array($attributes['permissions'])
                ? array_values($attributes['permissions'])
                : null;

            if (! empty($attributes['role']) || $permissions !== null) {
                if (! empty($attributes['role'])) {
                    $this->assertAppointableRole($roleSlug, $actor);
                    $this->assertRoleChangeAllowed($admin, $roleSlug, $actor);
                }

                $this->applyDeskAccess($admin, $roleSlug, $permissions, $actor);
            }

            if (array_key_exists('status', $attributes) && $attributes['status'] !== null) {
                $this->applyStatus($admin, UserStatus::from((string) $attributes['status']), $actor);
            }

            $fresh = $admin->fresh($this->defaultRelations());

            $this->rbac->audit($actor, 'admin.updated', $fresh, [
                'email' => $fresh->email,
                'previous_roles' => $previousRoles,
                'roles' => $fresh->roleSlugs()->values()->all(),
                'permissions' => $permissions,
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
            $this->discardPersonalDeskRole($admin);
            $admin->update(['status' => UserStatus::Inactive]);

            if (! $this->canHardDelete($admin)) {
                return;
            }

            try {
                $admin->delete();
            } catch (\Throwable) {
                // Keep the deactivated row when historical records still reference it.
            }
        });
    }

    /**
     * Hard-delete only when no restricted historical rows still point at the user.
     */
    private function canHardDelete(User $admin): bool
    {
        if ($admin->staffProfile()->exists()
            || $admin->studentProfile()->exists()
            || $admin->guardianProfile()->exists()
            || $admin->authorProfile()->exists()
            || $admin->posts()->exists()
            || $admin->recordedPayments()->exists()
            || $admin->markedAttendance()->exists()
            || $admin->enteredScores()->exists()
            || $admin->uploadedDocuments()->exists()
            || $admin->assignedEnquiries()->exists()) {
            return false;
        }

        return ! DB::table('announcements')->where('created_by', $admin->id)->exists()
            && ! DB::table('conversations')->where('created_by', $admin->id)->exists()
            && ! DB::table('conversation_participants')->where('user_id', $admin->id)->exists()
            && ! DB::table('messages')->where('sender_id', $admin->id)->exists()
            && ! (Schema::hasTable('outbound_mails') && DB::table('outbound_mails')->where('sent_by', $admin->id)->exists());
    }

    public function assertManagedDeskUser(User $admin): void
    {
        if (! $this->isManagedDeskUser($admin)) {
            abort(404);
        }
    }

    public function isManagedDeskUser(User $admin): bool
    {
        if ($admin->hasAnyRole(...RoleSlug::appointableDeskRoles())) {
            return true;
        }

        return $admin->roles()->where('slug', 'like', 'desk_u_%')->exists();
    }

    /**
     * Full dossier for the Admin Users view sheet.
     *
     * @return array<string, mixed>
     */
    public function dossier(User $admin): array
    {
        $admin->loadMissing($this->defaultRelations());

        $base = (new AdminUserResource($admin))->resolve();
        $permissions = $admin->permissionSlugs()->values()->all();

        $permissionDetails = collect($permissions)->map(function (string $slug) {
            $case = PermissionSlug::tryFrom($slug);

            return [
                'slug' => $slug,
                'name' => $case?->label() ?? $slug,
                'module' => $case?->module() ?? 'Other',
            ];
        })->groupBy('module')->map(fn ($items, $module) => [
            'module' => $module,
            'permissions' => $items->values()->all(),
        ])->values()->all();

        $roleRows = $admin->roles->map(function (Role $role) {
            $slug = (string) ($role->slug instanceof RoleSlug ? $role->slug->value : $role->slug);
            $isPersonal = str_starts_with($slug, 'desk_u_');

            return [
                'slug' => $slug,
                'name' => $isPersonal ? 'Custom desk access' : $role->name,
                'is_personal' => $isPersonal,
                'is_system_role' => (bool) $role->is_system_role,
                'permissions_count' => $role->permissions->count(),
            ];
        })->values()->all();

        $logins = LoginActivity::query()
            ->where('user_id', $admin->id)
            ->orderByDesc('logged_in_at')
            ->limit(25)
            ->get();

        $totalLogins = LoginActivity::query()->where('user_id', $admin->id)->count();
        $lastLogin = $logins->first();

        return array_merge($base, [
            'role_details' => $roleRows,
            'permission_groups' => $permissionDetails,
            'permissions_count' => count($permissions),
            'login_summary' => [
                'total_logins' => $totalLogins,
                'last_login_at' => $lastLogin?->logged_in_at?->toIso8601String(),
                'last_portal' => $lastLogin?->portal instanceof \App\Enums\AuthPortal
                    ? $lastLogin->portal->value
                    : ($lastLogin?->portal ? (string) $lastLogin->portal : null),
                'recent' => $logins->map(fn (LoginActivity $row) => [
                    'logged_in_at' => $row->logged_in_at?->toIso8601String(),
                    'portal' => $row->portal instanceof \App\Enums\AuthPortal
                        ? $row->portal->value
                        : (string) $row->portal,
                    'ip_address' => $row->ip_address,
                ])->values()->all(),
            ],
        ]);
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

    public function personalDeskRoleSlug(User $user): string
    {
        return 'desk_u_'.$user->id;
    }

    public function templateRoleSlug(User $admin): string
    {
        $personal = $admin->roles->first(
            fn (Role $role) => str_starts_with((string) $role->slug, 'desk_u_')
        );

        if ($personal !== null && is_string($personal->description)
            && preg_match('/(?:^|\b)template:([a-z0-9_]+)/', $personal->description, $matches) === 1) {
            return $matches[1];
        }

        $primary = $admin->roles->first(
            fn (Role $role) => ! str_starts_with((string) $role->slug, 'desk_u_')
        );

        return $primary !== null
            ? (string) ($primary->slug instanceof RoleSlug ? $primary->slug->value : $primary->slug)
            : '';
    }

    /**
     * @param  list<string>|null  $permissions
     */
    private function applyDeskAccess(User $user, string $roleSlug, ?array $permissions, User $actor): void
    {
        if ($roleSlug === RoleSlug::SuperAdmin->value) {
            $this->discardPersonalDeskRole($user);
            $this->rbac->assignUserRoles($user, [RoleSlug::SuperAdmin->value], $actor);

            return;
        }

        if ($permissions === null) {
            $this->discardPersonalDeskRole($user);
            $this->rbac->assignUserRoles($user, [$roleSlug], $actor);

            return;
        }

        $normalized = $this->normalizeDeskPermissions($permissions);
        $slug = $this->personalDeskRoleSlug($user);
        $templateName = Role::query()->where('slug', $roleSlug)->value('name') ?: $roleSlug;

        $role = Role::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $templateName.' · '.$user->name,
                'description' => 'template:'.$roleSlug,
                'is_system_role' => false,
            ]
        );

        $role->syncPermissions($normalized);
        $this->rbac->assignUserRoles($user, [$slug], $actor);
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function normalizeDeskPermissions(array $permissions): array
    {
        $slugs = collect($permissions)
            ->map(fn ($permission) => (string) $permission)
            ->filter()
            ->unique()
            ->values();

        if ($slugs->isEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'Select at least one desk permission.',
            ]);
        }

        if (! $slugs->contains(PermissionSlug::DeskView->value)
            && ! $slugs->contains(PermissionSlug::DeskAdminister->value)) {
            throw ValidationException::withMessages([
                'permissions' => 'Desk access requires the dashboard permission (desk.view).',
            ]);
        }

        $forbidden = $slugs->filter(function (string $slug) {
            $case = PermissionSlug::tryFrom($slug);

            return $case !== null && $case->isSuperAdminOnly();
        });

        if ($forbidden->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'Admin-user permissions can only be held by a super administrator.',
            ]);
        }

        $known = Permission::query()->whereIn('slug', $slugs->all())->pluck('slug')->map(fn ($slug) => (string) $slug);

        if ($known->count() !== $slugs->count()) {
            throw ValidationException::withMessages([
                'permissions' => 'One or more permissions could not be found.',
            ]);
        }

        return $slugs->all();
    }

    private function discardPersonalDeskRole(User $user): void
    {
        $slug = $this->personalDeskRoleSlug($user);
        $role = Role::query()->where('slug', $slug)->first();

        if ($role === null) {
            return;
        }

        $role->permissions()->detach();
        $role->users()->detach();
        $role->delete();
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
