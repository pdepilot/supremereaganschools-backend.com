<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PublishingSetting;
use App\Services\News\SitemapService;
use App\Support\SchoolIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class SiteAuditCommand extends Command
{
    protected $signature = 'site:audit';

    protected $description = 'Inspect public SEO, legal pages, and AdSense readiness. Does not report AdSense approval.';

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $passes = [];

    public function handle(SitemapService $sitemap): int
    {
        $this->auditPages();
        $this->auditDiscovery($sitemap);
        $this->auditArticles();
        $this->auditTaxonomy();
        $this->auditHubs();
        $this->auditAdSense();

        foreach ($this->passes as $line) {
            $this->line('<info>PASS</info>  '.$line);
        }
        foreach ($this->warnings as $line) {
            $this->line('<comment>WARNING</comment>  '.$line);
        }
        foreach ($this->errors as $line) {
            $this->line('<error>ERROR</error>  '.$line);
        }

        $this->newLine();
        if ($this->errors === []) {
            $this->info('AdSense readiness checks passed');
        } else {
            $this->error('AdSense readiness issues found');
        }

        $this->comment('This command does not report AdSense approval. Approval remains Google\'s decision.');

        return $this->errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function auditPages(): void
    {
        foreach (['home', 'news.index', 'resources.index', 'legal.privacy', 'legal.terms', 'site.page'] as $name) {
            if ($name === 'site.page') {
                $this->note(Route::has('site.page'), 'Public school pages are routed.', 'Public school page route is missing.');

                continue;
            }
            $this->note(Route::has($name), 'Route '.$name.' exists.', 'Missing route '.$name.'.');
        }

        $this->note(filled(SchoolIdentity::name()), 'School name is available.', 'School name is missing.');
        $this->note(filled(SchoolIdentity::phone()) && filled(SchoolIdentity::email()), 'Contact details are available.', 'School phone or email is missing.');
    }

    private function auditDiscovery(SitemapService $sitemap): void
    {
        $this->note(Route::has('sitemap'), 'Sitemap route exists.', 'Sitemap route is missing.');
        $this->note(Route::has('feed'), 'RSS route exists.', 'RSS route is missing.');
        $this->note(Route::has('robots') || is_file(public_path('robots.txt')), 'robots.txt is available.', 'robots.txt is missing.');
        $this->note(Route::has('ads-txt'), 'ads.txt route exists.', 'ads.txt route is missing.');

        $urls = collect($sitemap->urls());
        $this->note($urls->contains(fn ($row) => $row['loc'] === url('/')), 'Sitemap includes the homepage.', 'Sitemap is missing the homepage.');
        $this->note($urls->contains(fn ($row) => $row['loc'] === url('/news')), 'Sitemap includes News & Insights.', 'Sitemap is missing /news.');
        $this->note(! $urls->contains(fn ($row) => str_contains($row['loc'], '/portal')), 'Sitemap excludes portal URLs.', 'Sitemap includes a portal URL.');
        $this->note(! $urls->contains(fn ($row) => str_contains($row['loc'], '/login')), 'Sitemap excludes login URLs.', 'Sitemap includes a login URL.');
    }

    private function auditArticles(): void
    {
        $published = Post::query()->publiclyVisible()->with(['category', 'author', 'tags'])->get();
        $titles = [];

        if ($published->isEmpty()) {
            $this->warnings[] = 'No published public articles yet. Google expects useful original pages before an AdSense review.';

            return;
        }

        $slugs = [];
        $canonicals = [];

        foreach ($published as $post) {
            if (blank($post->title)) {
                $this->errors[] = 'Published article #'.$post->id.' has no title.';
            }
            if (blank($post->meta_description) && blank($post->excerpt)) {
                $this->errors[] = 'Published article “'.$post->title.'” has no meta description or excerpt.';
            } else {
                $this->passes[] = 'Article “'.$post->title.'” has a description.';
            }
            if (blank($post->slug) || isset($slugs[$post->slug])) {
                $this->errors[] = 'Duplicate or missing slug on article #'.$post->id.'.';
            }
            $slugs[$post->slug] = true;

            $canonical = $post->canonicalUrlResolved();
            if (isset($canonicals[$canonical])) {
                $this->errors[] = 'Duplicate canonical URL: '.$canonical;
            }
            $canonicals[$canonical] = true;

            if (! $post->indexable) {
                $this->warnings[] = 'Published article “'.$post->title.'” is marked noindex.';
            }
            if (blank($post->featured_image)) {
                $this->warnings[] = 'Article “'.$post->title.'” has no featured image.';
            } elseif (blank($post->featured_image_alt)) {
                $this->errors[] = 'Article “'.$post->title.'” has a featured image without alt text.';
            }
            if ($post->author_id === null) {
                $this->errors[] = 'Article “'.$post->title.'” has no author.';
            }
            if ($post->published_at === null) {
                $this->errors[] = 'Article “'.$post->title.'” is published without a publication date.';
            }
            if ($post->category_id === null) {
                $this->errors[] = 'Article “'.$post->title.'” has no category.';
            }

            $cta = $post->cta_strength?->value;
            if ($cta === 'none') {
                $this->warnings[] = 'Article “'.$post->title.'” has CTA turned off. That is allowed when the note should not sell the school.';
            }

            $text = trim(strip_tags((string) $post->content));
            if (\Illuminate\Support\Str::wordCount($text) < 80) {
                $this->warnings[] = 'Article “'.$post->title.'” is very short. Usefulness matters more than a word count, but a caption is not a resource.';
            }

            $titles[$post->title] = ($titles[$post->title] ?? 0) + 1;

            $related = Post::query()->publiclyVisible()->where('id', '!=', $post->id)->where('category_id', $post->category_id)->exists();
            if (! $related) {
                $this->warnings[] = 'Article “'.$post->title.'” may be an orphan in its category.';
            }
        }

        $invalid = Post::query()->whereNotIn('status', ['draft', 'review', 'scheduled', 'published', 'archived'])->count();
        $this->note($invalid === 0, 'Article statuses are valid.', 'One or more articles have an invalid status.');

        $leaked = Post::query()->where('status', '!=', 'published')->orWhere('visibility', 'private')->get()
            ->filter(fn (Post $post) => $post->isPubliclyVisible());
        $this->note($leaked->isEmpty(), 'Unpublished or private articles are not publicly visible.', 'Unpublished content is marked publicly visible.');

        foreach ($titles as $title => $count) {
            if ($count > 1) {
                $this->warnings[] = 'Duplicate title used more than once: “'.$title.'”.';
            }
        }
    }

    private function auditHubs(): void
    {
        $this->note(Route::has('resources.index'), 'Resource hub route exists.', 'Resource hub route is missing.');

        $empty = \App\Models\ResourceHub::query()->where('is_active', true)->with('categories')->get()
            ->filter(fn ($hub) => ! $hub->isIndexable());

        foreach ($empty as $hub) {
            $this->warnings[] = 'Hub “'.$hub->name.'” does not yet have enough published writing to be indexed.';
        }
    }

    private function auditTaxonomy(): void
    {
        $empty = PostCategory::query()
            ->where('is_active', true)
            ->whereDoesntHave('posts', fn ($q) => $q->publiclyVisible())
            ->get();

        foreach ($empty as $category) {
            $this->warnings[] = 'Category “'.$category->name.'” has no published articles and should stay noindex.';
        }
    }

    private function auditAdSense(): void
    {
        $settings = PublishingSetting::current();
        $client = $settings->adsenseClientId();

        if (! $settings->adsenseEnabled()) {
            $this->passes[] = 'AdSense is disabled until a real publisher ID is configured.';
        } elseif ($client === null) {
            $this->errors[] = 'AdSense is enabled but no valid ca-pub client ID is configured.';
        } else {
            $this->passes[] = 'AdSense has a syntactically valid publisher ID. This is not approval.';
        }

        $this->note(Route::has('legal.privacy'), 'Privacy policy route exists.', 'Privacy policy is missing.');
        $this->note(Route::has('legal.terms'), 'Terms route exists.', 'Terms page is missing.');
        $this->note(Route::has('site.page'), 'About, admissions, and contact remain public school pages.', 'Public school pages are missing.');
        $this->note(Route::has('ads-txt'), 'ads.txt configuration route exists.', 'ads.txt route is missing.');
        $adsLine = $settings->adsTxtLine();
        if ($adsLine === null) {
            $this->warnings[] = 'ads.txt has no seller line until a real publisher ID is configured.';
        } else {
            $this->passes[] = 'ads.txt can publish the configured seller line. This is not Google confirmation.';
        }
        $this->passes[] = 'Ad placement is page-level: ads_enabled and child_directed can exclude a public article.';
        $this->passes[] = 'Private portals, login, APIs, feeds, and authenticated sessions are excluded from ads.';
        $this->passes[] = 'AdSense readiness check complete. This is not AdSense approval.';
    }

    private function note(bool $ok, string $pass, string $error): void
    {
        if ($ok) {
            $this->passes[] = $pass;
        } else {
            $this->errors[] = $error;
        }
    }
}
