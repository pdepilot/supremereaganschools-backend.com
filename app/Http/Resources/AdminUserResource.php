<?php

namespace App\Http\Resources;

use App\Enums\RoleSlug;
use App\Models\User;
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
        $primaryRole = $this->roles->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
            'email' => $this->email,
            'status' => $this->status?->value,
            'roles' => $this->roleSlugs()->values()->all(),
            'role' => $primaryRole?->slug instanceof RoleSlug
                ? $primaryRole->slug->value
                : (string) ($primaryRole?->slug ?? ''),
            'role_name' => $primaryRole?->name,
            'permissions' => $this->permissionSlugs()->values()->all(),
            'is_super_admin' => $this->hasRole(RoleSlug::SuperAdmin),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
