<?php

namespace App\Services\News;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\ResourceHub;
use Illuminate\Support\Collection;

class ResourceHubService
{
    /**
     * @return Collection<int, ResourceHub>
     */
    public function active(): Collection
    {
        return ResourceHub::query()
            ->where('is_active', true)
            ->with('categories')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function findActive(string $slug): ?ResourceHub
    {
        return ResourceHub::query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->with('categories')
            ->first();
    }

    /**
     * @return Collection<int, Post>
     */
    public function featured(ResourceHub $hub, int $limit = 3): Collection
    {
        return $hub->publishedPosts()
            ->where(function ($q) {
                $q->where('is_featured', true)->orWhere('is_pinned', true);
            })
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Post>
     */
    public function latest(ResourceHub $hub, int $limit = 9, array $exceptIds = []): Collection
    {
        return $hub->publishedPosts()
            ->when($exceptIds !== [], fn ($q) => $q->whereNotIn('id', $exceptIds))
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, PostCategory>
     */
    public function relatedCategories(ResourceHub $hub): Collection
    {
        return $hub->categories
            ->filter(fn (PostCategory $category) => $category->is_active)
            ->values();
    }

    /**
     * @return Collection<int, Post>
     */
    public function parentResources(int $limit = 6): Collection
    {
        return Post::query()
            ->publiclyVisible()
            ->with(['category', 'author'])
            ->where(function ($q) {
                $q->where('is_parent_resource', true)
                    ->orWhereHas('category', fn ($c) => $c->where('slug', 'parenting'));
            })
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    public function anyHubIsIndexable(): bool
    {
        return $this->active()->contains(fn (ResourceHub $hub) => $hub->isIndexable());
    }
}
