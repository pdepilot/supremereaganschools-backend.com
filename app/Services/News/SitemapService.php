<?php

namespace App\Services\News;

use App\Models\Event;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\ResourceHub;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SitemapService
{
    /**
     * @return list<array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    public function urls(): array
    {
        try {
            app(PostService::class)->releaseScheduled();
        } catch (Throwable) {
            // Sitemap must still render if scheduled release cannot run.
        }

        $urls = [];

        foreach ($this->staticPages() as $path => $meta) {
            $urls[] = [
                'loc' => url($path),
                'lastmod' => $this->atomNow(),
                'changefreq' => $meta['changefreq'],
                'priority' => $meta['priority'],
            ];
        }

        if (Schema::hasTable('post_categories') && Schema::hasTable('posts')) {
            $categories = PostCategory::query()
                ->where('is_active', true)
                ->whereHas('posts', fn ($q) => $q->publiclyVisible())
                ->orderBy('sort_order')
                ->get();

            foreach ($categories as $category) {
                $slug = trim((string) $category->slug);
                if ($slug === '') {
                    continue;
                }

                $last = Post::query()->publiclyVisible()->where('category_id', $category->id)->max('updated_at');

                $urls[] = [
                    'loc' => url('/news/'.$slug),
                    'lastmod' => $this->atomFrom($last ?? $category->updated_at),
                    'changefreq' => 'weekly',
                    'priority' => '0.6',
                ];
            }
        }

        if (Schema::hasTable('resource_hubs') && Schema::hasTable('posts')) {
            $hubs = ResourceHub::query()->where('is_active', true)->with('categories')->get();
            $indexableHubs = $hubs->filter(fn (ResourceHub $hub) => $hub->isIndexable());

            if ($indexableHubs->isNotEmpty()) {
                $urls[] = [
                    'loc' => url('/resources'),
                    'lastmod' => $this->atomNow(),
                    'changefreq' => 'weekly',
                    'priority' => '0.7',
                ];
            }

            foreach ($indexableHubs as $hub) {
                $slug = trim((string) $hub->slug);
                if ($slug === '') {
                    continue;
                }

                $last = $hub->publishedPosts()->max('updated_at');
                $urls[] = [
                    'loc' => url('/resources/'.$slug),
                    'lastmod' => $this->atomFrom($last ?? $hub->updated_at),
                    'changefreq' => 'weekly',
                    'priority' => '0.65',
                ];
            }
        }

        if (Schema::hasTable('posts')) {
            $authors = Post::query()
                ->publiclyVisible()
                ->whereNotNull('author_id')
                ->select('author_id')
                ->distinct()
                ->pluck('author_id')
                ->filter(fn ($id) => filled($id) && (int) $id > 0);

            foreach ($authors as $authorId) {
                $last = Post::query()->publiclyVisible()->where('author_id', $authorId)->max('updated_at');
                $urls[] = [
                    'loc' => url('/news/authors/'.(int) $authorId),
                    'lastmod' => $this->atomFrom($last),
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
                $slug = trim((string) $post->slug);
                if ($slug === '') {
                    continue;
                }

                $urls[] = [
                    'loc' => $post->publicUrl(),
                    'lastmod' => $this->atomFrom($post->updated_at ?? $post->published_at),
                    'changefreq' => 'monthly',
                    'priority' => $post->is_featured ? '0.8' : '0.7',
                ];
            }
        }

        return $this->uniquePublicUrls($urls);
    }

    /**
     * @return array<string, array{changefreq: string, priority: string}>
     */
    private function staticPages(): array
    {
        $pages = [
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

        // Empty calendar shells stay out of the sitemap until real events exist.
        if ($this->shouldIndexEvents()) {
            $pages['/events'] = ['changefreq' => 'weekly', 'priority' => '0.7'];
        }

        // /pta is deliberately omitted: the public page has no verified PTA content yet.

        return $pages;
    }

    private function shouldIndexEvents(): bool
    {
        if (! Schema::hasTable('events')) {
            return false;
        }

        try {
            return Event::query()->published()->upcoming()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function atomNow(): string
    {
        return Carbon::now()->toAtomString();
    }

    private function atomFrom(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toAtomString();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value)->toAtomString();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toAtomString();
            } catch (Throwable) {
                // fall through
            }
        }

        return $this->atomNow();
    }

    /**
     * @param  list<array{loc: string, lastmod: string, changefreq: string, priority: string}>  $urls
     * @return list<array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function uniquePublicUrls(array $urls): array
    {
        $seen = [];
        $clean = [];

        foreach ($urls as $row) {
            $loc = trim((string) ($row['loc'] ?? ''));
            if ($loc === '' || str_contains($loc, '?') || str_contains($loc, '#')) {
                continue;
            }

            $path = (string) (parse_url($loc, PHP_URL_PATH) ?: '/');
            if (
                str_starts_with($path, '/portal')
                || str_starts_with($path, '/staff')
                || str_starts_with($path, '/parent')
                || str_starts_with($path, '/student')
                || str_starts_with($path, '/api')
                || str_starts_with($path, '/login')
                || $path === '/pta'
                || str_starts_with($path, '/pta/')
            ) {
                continue;
            }

            if (isset($seen[$loc])) {
                continue;
            }

            $seen[$loc] = true;
            $clean[] = $row;
        }

        return $clean;
    }
}
