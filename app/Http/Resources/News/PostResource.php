<?php

namespace App\Http\Resources\News;

use App\Models\Post;
use App\Services\News\PostService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Post
 */
class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $admin = $request->user()?->can('update', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'content' => $this->when($request->routeIs('v1.news.posts.show', 'v1.news.public.show') || $request->boolean('full'), $this->content),
            'featured_image' => $this->featured_image,
            'featured_image_url' => $this->featuredImageUrl(),
            'featured_image_alt' => $this->featured_image_alt,
            'status' => $this->when($admin, $this->status?->value),
            'visibility' => $this->when($admin, $this->visibility?->value),
            'author' => [
                'id' => $this->when($admin, $this->author_id),
                'name' => $this->authorName(),
                'role' => $this->authorRole(),
            ],
            'category' => new PostCategoryResource($this->whenLoaded('category')),
            'tags' => PostTagResource::collection($this->whenLoaded('tags')),
            'published_at' => $this->published_at?->toIso8601String(),
            'scheduled_at' => $this->when($admin, $this->scheduled_at?->toIso8601String()),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'canonical_url' => $this->canonicalUrlResolved(),
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'og_image' => $this->ogImageResolved(),
            'reading_time' => $this->reading_time,
            'views_count' => $this->viewsCount(),
            'allow_comments' => $this->allow_comments,
            'is_featured' => $this->is_featured,
            'is_pinned' => $this->is_pinned,
            'content_type' => $this->content_type?->value ?? 'article',
            'ads_enabled' => $this->when($admin, $this->ads_enabled),
            'indexable' => $this->when($admin, $this->indexable),
            'cta_type' => $this->when($admin, $this->cta_type?->value),
            'cta_strength' => $this->when($admin, $this->cta_strength?->value),
            'pillar_topic' => $this->when($admin, $this->pillar_topic),
            'supporting_topic' => $this->when($admin, $this->supporting_topic),
            'audience' => $this->when($admin, $this->audience?->value),
            'educational_level' => $this->when($admin, $this->educational_level?->value),
            'intent' => $this->when($admin, $this->intent?->value),
            'last_reviewed_at' => $this->when($admin, $this->last_reviewed_at?->toIso8601String()),
            'review_due_at' => $this->when($admin, $this->review_due_at?->toIso8601String()),
            'is_parent_resource' => $this->when($admin, $this->is_parent_resource),
            'child_directed' => $this->when($admin, $this->child_directed),
            'resource_path' => $this->when($admin, $this->resource_path),
            'resource_name' => $this->resource_original_name,
            'has_download' => $this->hasDownloadableResource(),
            'download_url' => $this->when($this->isPubliclyVisible() && $this->hasDownloadableResource(), $this->resourceDownloadUrl()),
            'author_url' => $this->authorPublicUrl(),
            'url' => $this->publicUrl(),
            'warnings' => $this->when(
                $admin,
                fn () => app(PostService::class)->qualityWarnings($this->resource),
            ),
            'checklist' => $this->when(
                $admin,
                fn () => app(PostService::class)->qualityChecklist($this->resource),
            ),
        ];
    }
}
