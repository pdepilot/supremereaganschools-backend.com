<?php

namespace App\Http\Resources;

use App\Enums\PermissionSlug;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $slug = $this->slug instanceof PermissionSlug
            ? $this->slug
            : PermissionSlug::tryFrom((string) $this->slug);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $slug?->value ?? (string) $this->slug,
            'module' => $this->module,
            'description' => $this->description,
            'pages' => $slug?->pages() ?? [],
            'sort_order' => $this->sort_order,
        ];
    }
}
