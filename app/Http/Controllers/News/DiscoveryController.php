<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PublishingSetting;
use App\Services\News\PostService;
use App\Services\News\SitemapService;
use Illuminate\Http\Response;

class DiscoveryController extends Controller
{
    public function sitemap(SitemapService $sitemap): Response
    {
        return response()
            ->view('site.discovery.sitemap', ['urls' => $sitemap->urls()])
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
            '',
            'Sitemap: '.url('/sitemap.xml'),
            '',
        ]);

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
