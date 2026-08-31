<?php

namespace Tests\Feature\News;

use App\Enums\RoleSlug;
use App\Models\PublishingSetting;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesNewsContext;
use Tests\TestCase;

class NewsAuthorizationAndAdSenseTest extends TestCase
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

    public function test_teachers_cannot_publish_or_open_the_news_desk(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $category = $this->newsCategory();

        $this->actingAs($teacher)->postJson('/api/v1/posts', [
            'title' => 'Unauthorized publish attempt',
            'content' => '<h2>No</h2><p>Teachers do not publish the public journal.</p>',
            'category_id' => $category->id,
            'status' => 'published',
        ])->assertForbidden();

        $this->actingAs($teacher)->getJson('/api/v1/posts')->assertForbidden();
        $this->actingAs($teacher)->get('/portal/news')->assertRedirect();
        $draft = $this->article(['status' => \App\Enums\PostStatus::Draft, 'slug' => 'teacher-preview', 'published_at' => null]);
        $this->actingAs($teacher)->get('/news/preview/'.$draft->id)->assertRedirect();
    }

    public function test_admin_can_preview_drafts_and_open_the_desk(): void
    {
        $admin = $this->admin();
        $draft = $this->article(['status' => \App\Enums\PostStatus::Draft, 'slug' => 'draft-preview', 'published_at' => null]);

        $this->actingAs($admin)->get('/portal/news')
            ->assertOk()
            ->assertSee('News & Insights', false)
            ->assertSee('portal-news.js', false)
            ->assertSee('data-news-remove', false)
            ->assertSee('data-news-image-preview', false)
            ->assertSee('data-news-pager', false)
            ->assertSee('noindex', false);

        $this->actingAs($admin)->get('/news/preview/'.$draft->id)
            ->assertOk()
            ->assertSee($draft->title, false)
            ->assertSee('noindex', false)
            ->assertDontSee('adsbygoogle', false);
    }

    public function test_adsense_stays_off_until_configured_and_never_hits_portals(): void
    {
        $article = $this->article(['slug' => 'adsense-off']);

        $this->get($article->publicUrl())
            ->assertOk()
            ->assertDontSee('adsbygoogle', false)
            ->assertDontSee('ca-pub-', false);

        $this->get('/ads.txt')->assertNotFound();

        $this->actingAs($this->admin())->get('/portal/dashboard')
            ->assertOk()
            ->assertDontSee('adsbygoogle', false);

        $this->actingAs($this->userWithRole(RoleSlug::Student))->get('/student')
            ->assertOk()
            ->assertDontSee('adsbygoogle', false);
    }

    public function test_adsense_renders_only_on_eligible_public_pages_with_consent(): void
    {
        $settings = PublishingSetting::current();
        $settings->fill([
            'adsense_enabled' => true,
            'adsense_client_id' => 'ca-pub-0000000000000000',
            'adsense_auto_ads' => true,
        ])->save();

        $article = $this->article(['slug' => 'adsense-on']);
        $blocked = $this->article(['slug' => 'ads-off-page', 'ads_enabled' => false]);

        $this->withCookie('srs_ad_consent', '1')
            ->get($article->publicUrl())
            ->assertOk()
            ->assertSee('pagead2.googlesyndication.com', false)
            ->assertSee('ca-pub-0000000000000000', false)
            ->assertSee('Advertisement', false);

        $this->withCookie('srs_ad_consent', '1')
            ->get($blocked->publicUrl())
            ->assertOk()
            ->assertDontSee('pagead2.googlesyndication.com', false);

        $this->actingAs($this->admin())->withCookie('srs_ad_consent', '1')->get($article->publicUrl())
            ->assertOk()
            ->assertDontSee('pagead2.googlesyndication.com', false);

        $this->get('/ads.txt')
            ->assertOk()
            ->assertSee('google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0', false);
    }

    public function test_analytics_loads_only_with_consent_and_sends_no_personal_data(): void
    {
        $settings = PublishingSetting::current();
        $settings->fill([
            'analytics_enabled' => true,
            'analytics_measurement_id' => 'G-L1TL37XYN7',
        ])->save();

        $article = $this->article(['slug' => 'analytics-note']);

        $this->get($article->publicUrl())
            ->assertOk()
            ->assertDontSee('G-L1TL37XYN7', false)
            ->assertDontSee('googletagmanager.com/gtag', false);

        $this->withCookie('srs_consent', json_encode(['analytics' => true]))
            ->get($article->publicUrl())
            ->assertOk()
            ->assertSee('googletagmanager.com/gtag/js?id=G-L1TL37XYN7', false)
            ->assertSee('anonymize_ip', false)
            ->assertDontSee($this->admin()->email, false);
    }

    public function test_ads_txt_serves_the_supplied_publisher_line(): void
    {
        $settings = PublishingSetting::current();
        $settings->fill([
            'adsense_enabled' => true,
            'adsense_client_id' => 'ca-pub-4828740366189357',
        ])->save();

        $this->get('/ads.txt')
            ->assertOk()
            ->assertSee('google.com, pub-4828740366189357, DIRECT, f08c47fec0942fa0', false)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }
}
