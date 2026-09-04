<?php

namespace App\Models;

use App\Enums\PermissionSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug', 'group', 'description', 'sort_order'])]
class Permission extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'slug' => PermissionSlug::class,
            'sort_order' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
