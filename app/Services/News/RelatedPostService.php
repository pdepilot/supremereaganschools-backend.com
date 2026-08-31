<?php

namespace App\Services\News;

use App\Models\Post;
use Illuminate\Support\Collection;

class RelatedPostService
{
    /**
     * @return Collection<int, Post>
     */
    public function for(Post $post, int $limit = 3): Collection
    {
        $tagIds = $post->tags->pluck('id');

        $candidates = Post::query()
            ->publiclyVisible()
            ->with(['category', 'author', 'tags'])
            ->where('id', '!=', $post->id)
            ->where(function ($query) use ($post, $tagIds) {
                $query->where('category_id', $post->category_id);

                if ($tagIds->isNotEmpty()) {
                    $query->orWhereHas('tags', fn ($tags) => $tags->whereIn('post_tags.id', $tagIds));
                }
            })
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(12)
            ->get();

        $ranked = $candidates->sortByDesc(function (Post $candidate) use ($post, $tagIds) {
            $score = 0;
            if ((int) $candidate->category_id === (int) $post->category_id) {
                $score += 4;
            }
            $score += $candidate->tags->pluck('id')->intersect($tagIds)->count();
            if ($candidate->content_type === $post->content_type) {
                $score += 2;
            }
            if (filled($post->pillar_topic) && $candidate->pillar_topic === $post->pillar_topic) {
                $score += 3;
            }
            if ($candidate->published_at?->gt(now()->subMonths(6))) {
                $score += 1;
            }

            return $score;
        });

        $picked = $ranked->take($limit)->values();

        if ($picked->count() >= $limit) {
            return $picked;
        }

        $extra = Post::query()
            ->publiclyVisible()
            ->with(['category', 'author'])
            ->where('id', '!=', $post->id)
            ->whereNotIn('id', $picked->pluck('id'))
            ->orderByDesc('published_at')
            ->limit($limit - $picked->count())
            ->get();

        return $picked->concat($extra)->values();
    }
}
