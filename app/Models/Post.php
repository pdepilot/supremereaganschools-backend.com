<?php

namespace App\Models;

use App\Enums\ContentAudience;
use App\Enums\ContentIntent;
use App\Enums\CtaDestination;
use App\Enums\CtaStrength;
use App\Enums\EducationalLevel;
use App\Enums\PostContentType;
use App\Enums\PostStatus;
use App\Enums\PostVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

#[Fillable([
    'title',
    'slug',
    'excerpt',
    'content',
    'featured_image',
    'featured_image_alt',
    'status',
    'visibility',
    'author_id',
    'category_id',
    'published_at',
    'scheduled_at',
    'meta_title',
    'meta_description',
    'canonical_url',
    'og_title',
    'og_description',
    'og_image',
    'reading_time',
    'allow_comments',
    'is_featured',
    'is_pinned',
    'ads_enabled',
    'indexable',
    'content_type',
    'cta_type',
    'cta_strength',
    'pillar_topic',
    'supporting_topic',
    'audience',
    'educational_level',
    'intent',
    'last_reviewed_at',
    'review_due_at',
    'resource_path',
    'resource_original_name',
    'is_parent_resource',
    'child_directed',
])]
class Post extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'visibility' => PostVisibility::class,
            'content_type' => PostContentType::class,
            'cta_type' => CtaDestination::class,
            'cta_strength' => CtaStrength::class,
            'audience' => ContentAudience::class,
            'educational_level' => EducationalLevel::class,
            'intent' => ContentIntent::class,
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
            'review_due_at' => 'datetime',
            'allow_comments' => 'boolean',
            'is_featured' => 'boolean',
            'is_pinned' => 'boolean',
            'ads_enabled' => 'boolean',
            'indexable' => 'boolean',
            'is_parent_resource' => 'boolean',
            'child_directed' => 'boolean',
            'views_count' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PostCategory::class, 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(PostTag::class, 'post_tag');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Published)
            ->where('visibility', PostVisibility::Public)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === PostStatus::Published
            && $this->visibility === PostVisibility::Public
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    public function authorName(): string
    {
        $name = trim((string) $this->author?->name);

        return $name !== '' ? $name : 'Supreme Reagan Schools Editorial Team';
    }

    public function publicUrl(): string
    {
        $category = $this->category?->slug ?: 'news';

        return url('/news/'.$category.'/'.$this->slug);
    }

    public function canonicalUrlResolved(): string
    {
        return filled($this->canonical_url) ? (string) $this->canonical_url : $this->publicUrl();
    }

    public function ogImageResolved(): ?string
    {
        $path = $this->og_image ?: $this->featured_image;

        if (! filled($path)) {
            return null;
        }

        return str_starts_with((string) $path, 'http') ? (string) $path : url((string) $path);
    }

    public function featuredImageUrl(): ?string
    {
        $path = trim((string) $this->featured_image);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return '/storage/'.ltrim($path, '/');
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    public function featuredImageDimensions(): array
    {
        $relative = (string) $this->featured_image;

        if ($relative === '' || ! str_starts_with($relative, '/storage/')) {
            return [null, null];
        }

        $path = storage_path('app/public/'.ltrim(substr($relative, strlen('/storage/')), '/'));

        if (! is_file($path)) {
            return [null, null];
        }

        $size = @getimagesize($path);

        return is_array($size) ? [(int) $size[0], (int) $size[1]] : [null, null];
    }

    public function authorRole(): string
    {
        $title = trim((string) $this->author?->staffProfile?->job_title);

        return $title !== '' ? $title : 'Supreme Reagan Schools Editorial Team';
    }

    public function wasMateriallyUpdated(): bool
    {
        if ($this->published_at === null || $this->updated_at === null) {
            return false;
        }

        return $this->updated_at->gt($this->published_at->copy()->addHours(12));
    }

    public function reviewIsDue(): bool
    {
        return $this->review_due_at !== null && $this->review_due_at->lte(now());
    }

    public function hasDownloadableResource(): bool
    {
        return filled($this->resource_path);
    }

    public function resourceDownloadUrl(): ?string
    {
        if (! $this->hasDownloadableResource() || $this->category === null) {
            return null;
        }

        return url('/news/'.$this->category->slug.'/'.$this->slug.'/download');
    }

    public function resourceFileUrl(): ?string
    {
        if (! filled($this->resource_path)) {
            return null;
        }

        return str_starts_with((string) $this->resource_path, 'http')
            ? (string) $this->resource_path
            : url((string) $this->resource_path);
    }

    public function viewsCount(): int
    {
        return (int) ($this->views_count ?? 0);
    }

    public function recordPublicView(): void
    {
        if (! Schema::hasTable('posts') || ! Schema::hasColumn('posts', 'views_count')) {
            return;
        }

        $this->increment('views_count');
        $this->refresh();
    }

    public function authorPublicUrl(): ?string
    {
        if ($this->author_id === null) {
            return null;
        }

        return url('/news/authors/'.$this->author_id);
    }

    public function socialTitle(): string
    {
        return (string) ($this->og_title ?: $this->meta_title ?: $this->title);
    }

    public function socialDescription(): string
    {
        return (string) ($this->og_description ?: $this->meta_description ?: $this->excerpt);
    }
}
