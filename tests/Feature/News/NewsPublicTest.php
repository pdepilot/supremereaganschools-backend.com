<?php

namespace Tests\Feature\News;

use App\Enums\PostStatus;
use App\Enums\PostVisibility;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesNewsContext;
use Tests\TestCase;

class NewsPublicTest extends TestCase
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

    public function test_public_index_article_search_pagination_and_related(): void
    {
        $category = $this->newsCategory();
        $featured = $this->article([
            'title' => 'How Extracurricular Activities Build Leadership Skills',
            'slug' => 'extracurricular-leadership',
            'is_featured' => true,
            'category_id' => $category->id,
        ]);
        $related = $this->article([
            'title' => 'How to Prepare for WAEC Without Unnecessary Pressure',
            'slug' => 'prepare-for-waec',
            'category_id' => $category->id,
            'published_at' => now()->subHours(2),
        ]);
        $related->tags()->sync([$this->newsTag('WAEC')->id]);
        $featured->tags()->sync([$this->newsTag('WAEC')->id]);

        for ($i = 1; $i <= 10; $i++) {
            $this->article([
                'title' => 'House note '.$i.' on calm study',
                'slug' => 'house-note-'.$i,
                'published_at' => now()->subDays($i + 2),
            ]);
        }

        $this->get('/news')
            ->assertOk()
            ->assertSee('News & Insights')
            ->assertSee('/site/CSS/news.css', false)
            ->assertSee('page-header', false)
            ->assertSee('blog-card', false)
            ->assertSee('Popular content', false)
            ->assertSee('classic-menu-wing', false)
            ->assertSee('classic-menu-house-trigger', false)
            ->assertSee('href="/news" class="is-current"', false)
            ->assertSee($featured->title, false)
            ->assertSee('How Extracurricular Activities Build Leadership Skills', false);

        $this->get('/news?q=WAEC')
            ->assertOk()
            ->assertSee($related->title, false)
            ->assertSee('noindex', false);

        $this->get('/news')
            ->assertOk()
            ->assertSee('Showing 1–9 of', false);

        $this->get('/news?page=2')
            ->assertOk()
            ->assertSee('Showing 10–', false)
            ->assertSee('page=1', false);

        $this->get($featured->publicUrl())
            ->assertOk()
            ->assertSee($featured->title, false)
            ->assertSee('Published:', false)
            ->assertSee($featured->authorName(), false)
            ->assertSee('Related from the house', false)
            ->assertSee($related->title, false)
            ->assertSee('application/ld+json', false)
            ->assertSee('BreadcrumbList', false)
            ->assertSee($featured->canonicalUrlResolved(), false)
            ->assertSee('og:title', false)
            ->assertDontSee($featured->title.' '.$featured->title, false);

        $this->get('/news/'.$category->slug)
            ->assertOk()
            ->assertSee($featured->title, false);
    }

    public function test_article_with_featured_image_renders(): void
    {
        $post = $this->article([
            'slug' => 'beyond-the-classroom',
            'featured_image' => '/site/Image/class_pics1.jpg',
            'featured_image_alt' => 'Pupils at work',
        ]);

        $this->get($post->publicUrl())
            ->assertOk()
            ->assertSee($post->title, false)
            ->assertSee('/site/Image/class_pics1.jpg', false)
            ->assertSee('blog-article-cover', false);
    }

    public function test_public_article_records_and_shows_views_once_per_session(): void
    {
        $post = $this->article(['slug' => 'views-on-the-journal']);

        $this->get($post->publicUrl())
            ->assertOk()
            ->assertSee('1 view', false);

        $this->assertSame(1, $post->fresh()->viewsCount());

        $this->get($post->publicUrl())
            ->assertOk()
            ->assertSee('1 view', false);

        $this->assertSame(1, $post->fresh()->viewsCount());

        $this->get('/news')
            ->assertOk()
            ->assertSee('1 view', false);
    }

    public function test_unpublished_and_private_articles_are_not_public(): void
    {
        $draft = $this->article(['status' => PostStatus::Draft, 'slug' => 'draft-article', 'published_at' => null]);
        $private = $this->article([
            'slug' => 'private-article',
            'visibility' => PostVisibility::Private,
        ]);

        $this->get('/news/school-news/draft-article')->assertNotFound();
        $this->get('/news/school-news/private-article')->assertNotFound();
        $this->getJson('/api/v1/news/'.$draft->slug)->assertNotFound();
        $this->getJson('/api/v1/news/'.$private->slug)->assertNotFound();
        $this->getJson('/api/v1/news')->assertOk()->assertJsonMissing(['slug' => 'draft-article']);
    }

    public function test_deleted_article_returns_404_not_homepage(): void
    {
        $post = $this->article(['slug' => 'removed-note']);
        $url = $post->publicUrl();
        $post->delete();

        $this->get($url)->assertNotFound()->assertDontSee('How a child joins the house', false);
    }

    public function test_wrong_category_redirects_to_canonical_article_url(): void
    {
        $post = $this->article(['slug' => 'science-and-technology']);

        $this->get('/news/admissions/'.$post->slug)
            ->assertRedirect($post->publicUrl());
    }

    public function test_legal_and_contact_pages_are_public(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy', false)->assertSee('Cookies', false);
        $this->get('/terms')->assertOk()->assertSee('Terms of Use', false);
        $this->get('/contact')->assertOk()->assertSee('09065641343', false);
        $this->get('/about')->assertOk()->assertSee('Who We Are', false);
    }
}
