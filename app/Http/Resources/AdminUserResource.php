<?php

namespace App\Http\Resources;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminUserService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $parts = preg_split('/\s+/', trim((string) $this->name), 2) ?: [];
        $templateSlug = app(AdminUserService::class)->templateRoleSlug($this->resource);
        $templateRole = $templateSlug !== ''
            ? Role::query()->where('slug', $templateSlug)->first()
            : null;
        $personal = $this->roles->first(
            fn (Role $role) => str_starts_with((string) $role->slug, 'desk_u_')
        );
        $primaryRole = $personal
            ?? $this->roles->first(fn (Role $role) => ! str_starts_with((string) $role->slug, 'desk_u_'))
            ?? $this->roles->first();

        $roleSlug = $templateSlug !== ''
            ? $templateSlug
            : (
                $primaryRole?->slug instanceof RoleSlug
                    ? $primaryRole->slug->value
                    : (string) ($primaryRole?->slug ?? '')
            );

        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
            'email' => $this->email,
            'status' => $this->status?->value,
            'roles' => $this->roleSlugs()->values()->all(),
            'role' => $roleSlug,
            'role_name' => $templateRole?->name ?? $primaryRole?->name,
            'permissions' => $this->permissionSlugs()->values()->all(),
            'is_super_admin' => $this->hasRole(RoleSlug::SuperAdmin),
            'has_custom_permissions' => $personal !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
