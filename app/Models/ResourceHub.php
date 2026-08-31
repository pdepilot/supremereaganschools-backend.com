<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

#[Fillable([
    'name',
    'slug',
    'kicker',
    'intro',
    'meta_title',
    'meta_description',
    'cta_type',
    'cta_copy',
    'is_parent_hub',
    'is_active',
    'sort_order',
])]
class ResourceHub extends Model
{
    public const MIN_INDEXABLE_POSTS = 2;

    protected function casts(): array
    {
        return [
            'is_parent_hub' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(PostCategory::class, 'resource_hub_category');
    }

    /**
     * @return Collection<int, Post>
     */
    public function publishedPosts()
    {
        $categoryIds = $this->categories->pluck('id');

        return Post::query()
            ->publiclyVisible()
            ->with(['category', 'author.staffProfile', 'tags'])
            ->when(
                $categoryIds->isNotEmpty(),
                fn ($q) => $q->whereIn('category_id', $categoryIds),
                fn ($q) => $q->whereRaw('0 = 1'),
            )
            ->orderByDesc('is_featured')
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at');
    }

    public function publishedCount(): int
    {
        return (int) $this->publishedPosts()->count();
    }

    public function isIndexable(): bool
    {
        return $this->is_active && $this->publishedCount() >= self::MIN_INDEXABLE_POSTS;
    }

    public function publicUrl(): string
    {
        return url('/resources/'.$this->slug);
    }
}
