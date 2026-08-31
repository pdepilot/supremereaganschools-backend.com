<?php

namespace App\Services\News;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\ResourceHub;
use Illuminate\Support\Carbon;

class SitemapService
{
    /**
     * @return list<array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    public function urls(): array
    {
        app(PostService::class)->releaseScheduled();

        $urls = [];

        foreach ($this->staticPages() as $path => $meta) {
            $urls[] = [
                'loc' => url($path),
                'lastmod' => Carbon::now()->toAtomString(),
                'changefreq' => $meta['changefreq'],
                'priority' => $meta['priority'],
            ];
        }

        $categories = PostCategory::query()
            ->where('is_active', true)
            ->whereHas('posts', fn ($q) => $q->publiclyVisible())
            ->orderBy('sort_order')
            ->get();

        foreach ($categories as $category) {
            $last = Post::query()->publiclyVisible()->where('category_id', $category->id)->max('updated_at');

            $urls[] = [
                'loc' => url('/news/'.$category->slug),
                'lastmod' => ($last ? Carbon::parse($last) : $category->updated_at)?->toAtomString() ?? now()->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ];
        }

        $hubs = ResourceHub::query()->where('is_active', true)->with('categories')->get();
        $indexableHubs = $hubs->filter(fn (ResourceHub $hub) => $hub->isIndexable());

        if ($indexableHubs->isNotEmpty()) {
            $urls[] = [
                'loc' => url('/resources'),
                'lastmod' => now()->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
        }

        foreach ($indexableHubs as $hub) {
            $last = $hub->publishedPosts()->max('updated_at');
            $urls[] = [
                'loc' => $hub->publicUrl(),
                'lastmod' => ($last ? Carbon::parse($last) : $hub->updated_at)?->toAtomString() ?? now()->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.65',
            ];
        }

        $authors = Post::query()
            ->publiclyVisible()
            ->select('author_id')
            ->distinct()
            ->pluck('author_id');

        foreach ($authors as $authorId) {
            $last = Post::query()->publiclyVisible()->where('author_id', $authorId)->max('updated_at');
            $urls[] = [
                'loc' => url('/news/authors/'.$authorId),
                'lastmod' => ($last ? Carbon::parse($last) : now())->toAtomString(),
                'changefreq' => 'monthly',
                'priority' => '0.4',
            ];
        }

        $posts = Post::query()
            ->publiclyVisible()
            ->where('indexable', true)
            ->with('category')
            ->orderByDesc('published_at')
            ->get();

        foreach ($posts as $post) {
            $urls[] = [
                'loc' => $post->publicUrl(),
                'lastmod' => ($post->updated_at ?? $post->published_at)?->toAtomString() ?? now()->toAtomString(),
                'changefreq' => 'monthly',
                'priority' => $post->is_featured ? '0.8' : '0.7',
            ];
        }

        return $urls;
    }

    /**
     * @return array<string, array{changefreq: string, priority: string}>
     */
    private function staticPages(): array
    {
        return [
            '/' => ['changefreq' => 'weekly', 'priority' => '1.0'],
            '/about' => ['changefreq' => 'monthly', 'priority' => '0.8'],
            '/admissions' => ['changefreq' => 'monthly', 'priority' => '0.8'],
            '/contact' => ['changefreq' => 'monthly', 'priority' => '0.7'],
            '/nursery' => ['changefreq' => 'monthly', 'priority' => '0.6'],
            '/primary' => ['changefreq' => 'monthly', 'priority' => '0.6'],
            '/secondary' => ['changefreq' => 'monthly', 'priority' => '0.6'],
            '/branches' => ['changefreq' => 'monthly', 'priority' => '0.5'],
            '/alumni' => ['changefreq' => 'monthly', 'priority' => '0.4'],
            '/news' => ['changefreq' => 'daily', 'priority' => '0.8'],
            '/privacy' => ['changefreq' => 'yearly', 'priority' => '0.3'],
            '/terms' => ['changefreq' => 'yearly', 'priority' => '0.3'],
        ];
    }
}
