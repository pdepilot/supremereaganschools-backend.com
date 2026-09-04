<?php

namespace App\Http\Resources;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $isSuper = $actor?->hasRole(RoleSlug::SuperAdmin) ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status?->value,
            'roles' => $this->roleSlugs()->values()->all(),
            'is_super_admin' => $this->hasRole(RoleSlug::SuperAdmin),
            'permissions' => $this->when(
                $isSuper || ($actor?->hasRole(RoleSlug::SchoolAdmin) ?? false),
                function () {
                    if ($this->hasRole(RoleSlug::SuperAdmin)) {
                        return collect(PermissionSlug::cases())->map->value->values()->all();
                    }

                    return $this->permissions
                        ->map(fn (Permission $permission) => $permission->slug instanceof PermissionSlug
                            ? $permission->slug->value
                            : (string) $permission->slug)
                        ->values()
                        ->all();
                },
            ),
        ];
    }
}
