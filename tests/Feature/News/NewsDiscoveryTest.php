<?php

namespace Tests\Feature\News;

use App\Enums\PostStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesNewsContext;
use Tests\TestCase;

class NewsDiscoveryTest extends TestCase
{
    use CreatesAcademicContext;
    use CreatesNewsContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seedNews();
    }

    public function test_sitemap_feed_robots_and_structured_data(): void
    {
        $live = $this->article(['slug' => 'live-on-sitemap', 'is_featured' => true]);
        $draft = $this->article(['slug' => 'hidden-draft', 'status' => PostStatus::Draft, 'published_at' => null]);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $sitemap->assertSee('<?xml version="1.0" encoding="UTF-8"?>', false)
            ->assertSee($live->publicUrl(), false)
            ->assertSee(url('/'), false)
            ->assertSee(url('/news'), false)
            ->assertSee(url('/about'), false)
            ->assertDontSee($draft->slug, false)
            ->assertDontSee('/portal', false)
            ->assertDontSee('/login', false)
            ->assertDontSee('q=', false);

        $this->get('/feed')
            ->assertOk()
            ->assertSee($live->title)
            ->assertSee($live->publicUrl(), false)
            ->assertDontSee('hidden-draft', false);

        $this->get('/rss')->assertOk()->assertSee($live->title);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /news', false)
            ->assertSee('Disallow: /portal', false)
            ->assertSee('Disallow: /student', false)
            ->assertSee('Disallow: /news/preview', false);

        $this->get($live->publicUrl())
            ->assertSee('"@type":"Article"', false)
            ->assertSee('"headline"', false)
            ->assertSee('datePublished', false)
            ->assertSee('EducationalOrganization', false);
    }

    public function test_site_audit_command_and_public_api(): void
    {
        $this->article(['slug' => 'audit-live']);

        $this->artisan('site:audit')
            ->expectsOutputToContain('PASS')
            ->expectsOutputToContain('AdSense readiness checks passed')
            ->expectsOutputToContain('does not report AdSense approval')
            ->assertSuccessful();

        $this->getJson('/api/v1/news')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['slug' => 'audit-live']);

        $this->getJson('/api/v1/news/audit-live')
            ->assertOk()
            ->assertJsonPath('data.slug', 'audit-live')
            ->assertJsonMissingPath('data.status');

        $this->getJson('/api/v1/news/categories')->assertOk();
        $this->getJson('/api/v1/news/tags')->assertOk();
    }
}
