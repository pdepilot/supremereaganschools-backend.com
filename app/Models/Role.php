<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug', 'description', 'is_system_role'])]
class Role extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_system_role' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    /**
     * @param  list<\App\Enums\PermissionSlug|Permission|string|int>  $permissions
     */
    public function syncPermissions(array $permissions): void
    {
        $ids = collect($permissions)
            ->map(function ($permission) {
                if ($permission instanceof Permission) {
                    return (int) $permission->id;
                }

                if ($permission instanceof \App\Enums\PermissionSlug) {
                    return (int) Permission::query()->where('slug', $permission->value)->firstOrFail()->id;
                }

                if (is_int($permission) || (is_string($permission) && ctype_digit($permission))) {
                    return (int) $permission;
                }

                return (int) Permission::query()->where('slug', (string) $permission)->firstOrFail()->id;
            })
            ->unique()
            ->values()
            ->all();

        $this->permissions()->sync($ids);
        $this->unsetRelation('permissions');
    }
}
