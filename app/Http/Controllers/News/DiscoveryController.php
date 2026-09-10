<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PublishingSetting;
use App\Services\News\PostService;
use App\Services\News\SitemapService;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Throwable;

class DiscoveryController extends Controller
{
    public function sitemap(SitemapService $sitemap): Response
    {
        try {
            $urls = $sitemap->urls();
        } catch (Throwable $e) {
            report($e);
            $urls = $this->fallbackSitemapUrls();
        }

        return response()
            ->view('site.discovery.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function feed(PostService $posts): Response
    {
        $posts->releaseScheduled();

        $articles = Post::query()
            ->publiclyVisible()
            ->with(['category', 'author'])
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();

        return response()
            ->view('site.discovery.feed', ['articles' => $articles])
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    public function adsTxt(): Response
    {
        $line = PublishingSetting::current()->adsTxtLine();

        if ($line === null) {
            abort(404);
        }

        return response(rtrim($line)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function robots(): Response
    {
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Allow: /news',
            'Allow: /resources',
            'Allow: /site/Image/',
            'Allow: /storage/news/',
            'Disallow: /portal',
            'Disallow: /staff',
            'Disallow: /parent',
            'Disallow: /student',
            'Disallow: /login',
            'Disallow: /api',
            'Disallow: /news/preview',
            'Disallow: /pta',
            '',
            'Sitemap: '.url('/sitemap.xml'),
            '',
        ]);

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * Minimal public URLs if the full sitemap builder fails in production.
     *
     * @return list<array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function fallbackSitemapUrls(): array
    {
        $now = Carbon::now()->toAtomString();

        return collect([
            '/' => '1.0',
            '/about' => '0.8',
            '/admissions' => '0.8',
            '/contact' => '0.7',
            '/news' => '0.8',
            '/privacy' => '0.3',
            '/terms' => '0.3',
        ])->map(fn (string $priority, string $path) => [
            'loc' => url($path),
            'lastmod' => $now,
            'changefreq' => 'weekly',
            'priority' => $priority,
        ])->values()->all();
    }
}
