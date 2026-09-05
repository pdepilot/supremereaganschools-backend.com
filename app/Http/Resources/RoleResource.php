<?php

namespace App\Http\Resources;

use App\Enums\RoleSlug;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $slug = (string) $this->slug;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $slug,
            'description' => $this->description,
            'is_system_role' => (bool) $this->is_system_role,
            'is_super_admin' => $slug === RoleSlug::SuperAdmin->value,
            'users_count' => $this->whenCounted('users'),
            'permissions_count' => $this->whenCounted('permissions'),
            'permissions' => $this->whenLoaded('permissions', function () {
                return $this->permissions->map(
                    fn (Permission $permission) => $permission->slug instanceof \App\Enums\PermissionSlug
                        ? $permission->slug->value
                        : (string) $permission->slug
                )->values()->all();
            }),
        ];
    }
}
