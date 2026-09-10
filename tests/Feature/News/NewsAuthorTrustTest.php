<?php

namespace Tests\Feature\News;

use App\Models\AuthorProfile;
use App\Models\Post;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesNewsContext;
use Tests\TestCase;

class NewsAuthorTrustTest extends TestCase
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

    public function test_author_role_prefers_public_role_then_staff_title_then_editorial_fallback(): void
    {
        $author = $this->admin();
        $post = $this->article(['slug' => 'role-priority', 'author_id' => $author->id]);

        $this->assertSame('Supreme Reagan Schools Editorial Team', $post->fresh(['author.authorProfile', 'author.staffProfile'])->authorRole());

        $this->staff($author, ['job_title' => 'Head of Junior School']);
        $post = $post->fresh(['author.authorProfile', 'author.staffProfile']);
        $this->assertSame('Head of Junior School', $post->authorRole());

        AuthorProfile::query()->create([
            'user_id' => $author->id,
            'public_role' => 'Parent Guidance Editor',
            'biography' => 'Writes parent guidance notes for Supreme Reagan Schools families.',
        ]);
        $post = $post->fresh(['author.authorProfile', 'author.staffProfile']);
        $this->assertSame('Parent Guidance Editor', $post->authorRole());
    }

    public function test_news_cards_link_author_and_article_json_ld_uses_factual_fields(): void
    {
        $author = $this->userWithRole(\App\Enums\RoleSlug::SchoolAdmin, ['name' => 'Ada Editorial']);
        $category = $this->newsCategory('Parenting');
        $tag = $this->newsTag('Study Tips');

        $article = $this->article([
            'title' => 'How Parents Can Build Calm Evening Study',
            'slug' => 'calm-evening-study',
            'author_id' => $author->id,
            'category_id' => $category->id,
        ]);
        $article->tags()->sync([$tag->id]);

        $this->get('/news')
            ->assertOk()
            ->assertSee('href="'.url('/news/authors/'.$author->id).'"', false)
            ->assertSee('Ada Editorial', false);

        $response = $this->get($article->fresh(['category', 'tags', 'author'])->publicUrl())->assertOk();
        $response->assertSee('"@type":"Article"', false)
            ->assertSee('"url":"'.url('/news/authors/'.$author->id).'"', false)
            ->assertSee('"articleSection":"Parenting"', false)
            ->assertSee('"keywords":"Study Tips"', false)
            ->assertDontSee('aggregateRating', false)
            ->assertDontSee('PhD', false);
    }

    public function test_author_page_emits_person_json_ld_only_with_factual_profile(): void
    {
        $author = $this->userWithRole(\App\Enums\RoleSlug::SchoolAdmin, ['name' => 'Ezinne Desk']);
        $this->article(['slug' => 'desk-note', 'author_id' => $author->id]);

        $this->get('/news/authors/'.$author->id)
            ->assertOk()
            ->assertSee('Supreme Reagan Schools Editorial Team', false)
            ->assertDontSee('"@type":"Person"', false)
            ->assertDontSee('PhD', false);

        AuthorProfile::query()->create([
            'user_id' => $author->id,
            'public_role' => 'Admissions Correspondent',
            'biography' => 'Shares admissions guidance drawn from the school office.',
        ]);

        $this->get('/news/authors/'.$author->id)
            ->assertOk()
            ->assertSee('"@type":"Person"', false)
            ->assertSee('"jobTitle":"Admissions Correspondent"', false)
            ->assertSee('"description":"Shares admissions guidance drawn from the school office."', false)
            ->assertSee('"url":"'.url('/news/authors/'.$author->id).'"', false)
            ->assertDontSee('PhD', false);
    }

    public function test_title_case_migration_fixes_known_all_caps_titles(): void
    {
        $this->article([
            'title' => 'HOW TO HELP YOUR CHILD PREPARE FOR A NEW SCHOOL TERM',
            'slug' => 'how-to-help-your-child-prepare-for-a-new-school-term',
            'meta_title' => 'HOW TO HELP YOUR CHILD PREPARE FOR A NEW SCHOOL TERM',
        ]);
        $this->article([
            'title' => 'HOW PARENTS CAN HELP CHILDREN DEVELOP BETTER STUDY HABITS',
            'slug' => 'how-parents-can-help-children-develop-better-study-habits',
        ]);

        $migration = require database_path('migrations/2026_09_10_210000_fix_all_caps_news_article_titles.php');
        $migration->up();

        $this->assertSame(
            'How to Help Your Child Prepare for a New School Term',
            Post::query()->where('slug', 'how-to-help-your-child-prepare-for-a-new-school-term')->value('title'),
        );
        $this->assertSame(
            'How to Help Your Child Prepare for a New School Term',
            Post::query()->where('slug', 'how-to-help-your-child-prepare-for-a-new-school-term')->value('meta_title'),
        );
        $this->assertSame(
            'How Parents Can Help Children Develop Better Study Habits',
            Post::query()->where('slug', 'how-parents-can-help-children-develop-better-study-habits')->value('title'),
        );
    }
}