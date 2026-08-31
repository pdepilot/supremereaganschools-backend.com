<?php

namespace Tests\Concerns;

use App\Enums\PostStatus;
use App\Enums\PostVisibility;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostTag;
use App\Models\User;
use App\Services\News\PostService;
use Database\Seeders\NewsInsightsSeeder;

trait CreatesNewsContext
{
    protected function seedNews(): void
    {
        $this->seed(NewsInsightsSeeder::class);
    }

    protected function newsCategory(string $name = 'School News'): PostCategory
    {
        $this->seedNews();

        return PostCategory::query()->where('slug', \Illuminate\Support\Str::slug($name))->firstOrFail();
    }

    protected function newsTag(string $name = 'Study Tips'): PostTag
    {
        $this->seedNews();

        return PostTag::query()->where('slug', \Illuminate\Support\Str::slug($name))->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function article(array $attributes = [], ?User $author = null): Post
    {
        $author ??= $this->admin();
        $category = $attributes['category_id'] ?? $this->newsCategory()->id;
        $title = (string) ($attributes['title'] ?? 'How Students Can Prepare for Examination Season');
        $slug = app(PostService::class)->uniqueSlug((string) ($attributes['slug'] ?? $title));
        unset($attributes['slug']);

        $post = new Post(array_merge([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => 'A calm plan for the weeks before papers, written for Supreme Reagan families.',
            'content' => '<h2>Begin with the timetable</h2><p>The house asks pupils to work from the real timetable, not from panic. Sleep, meals, and a short review each evening matter more than a late night of cramming.</p><p>Parents can help by keeping a quiet table and asking what went well, not only what was missed.</p>',
            'status' => PostStatus::Published,
            'visibility' => PostVisibility::Public,
            'author_id' => $author->id,
            'category_id' => $category,
            'published_at' => now()->subDay(),
            'meta_title' => 'How Students Can Prepare for Examination Season',
            'meta_description' => 'Practical examination-season guidance from Supreme Reagan Schools.',
            'featured_image_alt' => 'Pupils at study',
            'reading_time' => 3,
            'allow_comments' => false,
            'is_featured' => false,
            'is_pinned' => false,
            'ads_enabled' => true,
            'indexable' => true,
        ], $attributes));
        $post->save();

        return $post->fresh(['category', 'tags', 'author']) ?? $post;
    }
}
