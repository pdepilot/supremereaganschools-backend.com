<?php

namespace Tests\Feature\News;

use App\Enums\CtaStrength;
use App\Enums\PostContentType;
use App\Enums\PostStatus;
use App\Models\ContactEnquiry;
use App\Models\NewsletterSubscriber;
use App\Models\Post;
use App\Models\PublishingSetting;
use App\Models\ResourceHub;
use App\Services\News\PostService;
use App\Services\News\RelatedPostService;
use App\Services\News\SchoolCtaResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesNewsContext;
use Tests\TestCase;

class EducationResourceHubTest extends TestCase
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

    public function test_admin_can_store_content_types_and_editorial_fields(): void
    {
        $admin = $this->admin();
        $category = $this->newsCategory('Parenting');

        $created = $this->actingAs($admin)->postJson('/api/v1/posts', [
            'title' => 'How to Choose the Right Secondary School for Your Child',
            'content' => '<h2>Ask what the rooms actually do</h2><p>A parent should walk the campus, hear how reading is taught, and ask what happens when a child struggles.</p><p>Do not buy a slogan. Ask for the timetable and the ordinary day.</p>',
            'category_id' => $category->id,
            'status' => 'published',
            'content_type' => 'guide',
            'cta_type' => 'admissions',
            'cta_strength' => 'standard',
            'audience' => 'parents',
            'educational_level' => 'junior_secondary',
            'intent' => 'admissions',
            'pillar_topic' => 'Choosing a school',
            'supporting_topic' => 'Questions parents should ask',
            'is_parent_resource' => true,
        ])->assertCreated()->json('data');

        $this->assertSame('guide', $created['content_type']);
        $this->assertSame('admissions', $created['cta_type']);
        $this->assertSame('parents', $created['audience']);
        $this->assertNotEmpty($created['checklist']);

        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/news/'.$created['slug'])
            ->assertOk()
            ->assertJsonMissingPath('data.pillar_topic')
            ->assertJsonMissingPath('data.audience');
    }

    public function test_resource_hubs_are_noindex_until_they_have_enough_content(): void
    {
        $this->get('/resources')
            ->assertOk()
            ->assertSee('Education &amp; parent resource hub', false)
            ->assertSee('noindex', false)
            ->assertSee('house-trigger is-current', false)
            ->assertSee('href="/resources" class="is-current"', false);

        $this->get('/resources/parenting')
            ->assertOk()
            ->assertSee('Parent Resources', false)
            ->assertSee('noindex', false)
            ->assertSee('Academic support', false);

        $this->get('/resources/unknown-hub')->assertNotFound();

        $sitemap = $this->get('/sitemap.xml')->assertOk();
        $sitemap->assertDontSee(url('/resources/parenting'), false);

        $parenting = $this->newsCategory('Parenting');
        $this->article([
            'title' => 'How Parents Can Help Children Prepare for Examinations',
            'slug' => 'parents-exam-help',
            'category_id' => $parenting->id,
            'is_parent_resource' => true,
        ]);
        $this->article([
            'title' => 'How to Help Your Child Improve Reading Skills',
            'slug' => 'improve-reading',
            'category_id' => $parenting->id,
            'is_parent_resource' => true,
        ]);

        $this->get('/resources/parenting')
            ->assertOk()
            ->assertSee('index,follow', false)
            ->assertSee('How Parents Can Help Children Prepare for Examinations', false);

        $this->get('/sitemap.xml')->assertSee(url('/resources/parenting'), false);
        $this->get('/resources')->assertSee('index,follow', false);
    }

    public function test_article_renders_matching_cta_and_related_content(): void
    {
        $parenting = $this->newsCategory('Parenting');
        $guide = $this->article([
            'title' => 'How to Choose a Secondary School in Nigeria',
            'slug' => 'choose-secondary',
            'category_id' => $parenting->id,
            'content_type' => PostContentType::Guide,
            'cta_type' => null,
            'pillar_topic' => 'Choosing a school',
        ]);
        $related = $this->article([
            'title' => 'Questions Parents Should Ask a School',
            'slug' => 'questions-to-ask',
            'category_id' => $parenting->id,
            'content_type' => PostContentType::Guide,
            'pillar_topic' => 'Choosing a school',
        ]);

        $cta = app(SchoolCtaResolver::class)->for($guide);
        $this->assertSame('parent-resources', $cta['type']->value);

        $this->get($guide->publicUrl())
            ->assertOk()
            ->assertSee('Next step', false)
            ->assertSee('Parent resources', false)
            ->assertSee($related->title, false)
            ->assertSee('data-article-view', false)
            ->assertSee('Published:', false);

        $picked = app(RelatedPostService::class)->for($guide);
        $this->assertTrue($picked->contains(fn (Post $post) => $post->id === $related->id));
    }

    public function test_cta_none_does_not_render(): void
    {
        $post = $this->article([
            'slug' => 'quiet-note',
            'cta_type' => \App\Enums\CtaDestination::None,
            'cta_strength' => CtaStrength::None,
        ]);

        $this->get($post->publicUrl())
            ->assertOk()
            ->assertDontSee('school-cta-form', false)
            ->assertDontSee('data-cta-type="admissions"', false);
    }

    public function test_author_page_is_public_only_with_published_work(): void
    {
        $author = $this->admin();
        $this->article(['slug' => 'author-note', 'author_id' => $author->id]);

        $this->get('/news/authors/'.$author->id)
            ->assertOk()
            ->assertSee($author->name, false)
            ->assertSee('Supreme Reagan Schools Editorial Team', false)
            ->assertDontSee('PhD', false);

        $silent = $this->userWithRole(\App\Enums\RoleSlug::Teacher);
        $this->get('/news/authors/'.$silent->id)->assertNotFound();
    }

    public function test_homepage_shows_journal_when_articles_exist(): void
    {
        $this->article([
            'title' => 'How to Build Effective Study Habits',
            'slug' => 'study-habits',
            'is_featured' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('From the education desk', false)
            ->assertSee('How to Build Effective Study Habits', false)
            ->assertSee('How a child joins the house', false);
    }

    public function test_homepage_latest_slot_is_replaced_while_news_archive_keeps_all(): void
    {
        $older = $this->article([
            'title' => 'Older Campus Note',
            'slug' => 'older-campus-note',
            'published_at' => now()->subDays(2),
        ]);

        $newer = $this->article([
            'title' => 'Newer Campus Note',
            'slug' => 'newer-campus-note',
            'published_at' => now()->subHour(),
        ]);

        $home = $this->get('/')->assertOk();
        $home->assertSee('Newer Campus Note', false);
        $home->assertDontSee('Older Campus Note', false);

        $this->get('/news')
            ->assertOk()
            ->assertSee('Newer Campus Note', false)
            ->assertSee('Older Campus Note', false);

        $this->assertTrue($older->fresh()->isPubliclyVisible());
        $this->assertTrue($newer->fresh()->isPubliclyVisible());
    }

    public function test_admission_enquiry_accepts_level_and_source_without_public_leak(): void
    {
        $post = $this->article(['slug' => 'enquiry-source']);

        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'Mrs. Adaeze Okeke',
            'phone' => '08035550022',
            'email' => 'adaeze@example.test',
            'subject' => 'Admission enquiry',
            'message' => 'We would like to know about Junior Secondary entry for next session.',
            'intended_level' => 'junior_secondary',
            'enquiry_type' => 'admissions',
            'source_url' => $post->publicUrl(),
            'source_post_id' => $post->id,
        ])->assertCreated()->assertJsonPath('data.intended_level', 'junior_secondary');

        $this->assertDatabaseHas('contact_enquiries', [
            'email' => 'adaeze@example.test',
            'intended_level' => 'junior_secondary',
            'source_post_id' => $post->id,
        ]);

        $this->getJson('/api/v1/news')->assertOk()->assertJsonMissing(['email' => 'adaeze@example.test']);
        $this->get('/news')->assertOk()->assertDontSee('adaeze@example.test', false);
    }

    public function test_enquiry_validation_and_rate_limit(): void
    {
        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'Anon',
        ])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email', 'subject', 'message']);
    }

    public function test_enquiry_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/contact-enquiries', [
                'name' => 'Parent '.$i,
                'phone' => '0803000000'.$i,
                'email' => 'parent'.$i.'@example.test',
                'subject' => 'General correspondence',
                'message' => 'A short letter to the office.',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'Too Many',
            'phone' => '08039999999',
            'email' => 'too@example.test',
            'subject' => 'General correspondence',
            'message' => 'This should be limited.',
        ])->assertStatus(429);

        $this->assertSame(20, ContactEnquiry::query()->count());
    }

    public function test_newsletter_requires_consent_and_does_not_auto_subscribe(): void
    {
        $this->postJson('/news/subscribe', [
            'email' => 'family@example.test',
        ])->assertUnprocessable();

        $this->postJson('/news/subscribe', [
            'email' => 'family@example.test',
            'consent' => true,
            'source' => 'resources',
        ])->assertOk();

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'family@example.test',
            'status' => 'active',
        ]);
        $this->assertNotNull(NewsletterSubscriber::query()->first()?->consented_at);
    }

    public function test_child_directed_pages_exclude_ads(): void
    {
        $settings = PublishingSetting::current();
        $settings->fill([
            'adsense_enabled' => true,
            'adsense_client_id' => 'ca-pub-0000000000000000',
            'adsense_auto_ads' => true,
        ])->save();

        $blocked = $this->article([
            'slug' => 'for-children',
            'child_directed' => true,
            'ads_enabled' => true,
        ]);

        $this->withUnencryptedCookie('srs_ad_consent', '1')
            ->get($blocked->publicUrl())
            ->assertOk()
            ->assertDontSee('pagead2.googlesyndication.com', false);
    }

    public function test_quality_checklist_is_not_a_word_count_rule(): void
    {
        $post = $this->article(['slug' => 'quality-note']);
        $items = collect(app(PostService::class)->qualityChecklist($post))->keyBy('key');

        $this->assertTrue($items['title']['ok']);
        $this->assertArrayNotHasKey('word_count_1500', $items->all());
        $this->assertTrue($items['canonical']['ok']);
    }

    public function test_scheduled_content_stays_off_hubs_until_release(): void
    {
        $parenting = $this->newsCategory('Parenting');
        $this->article([
            'slug' => 'future-guide',
            'category_id' => $parenting->id,
            'status' => PostStatus::Scheduled,
            'published_at' => now()->addDay(),
            'scheduled_at' => now()->addDay(),
        ]);

        $hub = ResourceHub::query()->where('slug', 'parenting')->firstOrFail();
        $this->assertFalse($hub->isIndexable());
        $this->get('/resources/parenting')->assertDontSee('future-guide', false);
    }

    public function test_robots_allows_resources_and_audit_still_passes(): void
    {
        $this->article(['slug' => 'audit-hub']);

        $this->get('/robots.txt')->assertOk()->assertSee('Allow: /resources', false);

        $this->artisan('site:audit')
            ->expectsOutputToContain('AdSense readiness checks passed')
            ->expectsOutputToContain('does not report AdSense approval')
            ->assertSuccessful();
    }
}
