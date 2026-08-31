<?php

namespace Tests\Feature\News;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostCategory;
use App\Support\EditorialHtml;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesNewsContext;
use Tests\TestCase;

class NewsPublishingTest extends TestCase
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

    public function test_admin_can_create_update_and_workflow_an_article(): void
    {
        $admin = $this->admin();
        $category = $this->newsCategory();
        $tag = $this->newsTag();

        $created = $this->actingAs($admin)->postJson('/api/v1/posts', [
            'title' => 'How Parents Can Help Their Children Develop Better Study Habits',
            'content' => '<h2>A quiet table</h2><p>The house asks families to keep a regular hour, not a lecture. Ask what the child understood, then stop.</p><p>Phones stay outside that hour.</p>',
            'excerpt' => 'A short guide for parents in the Supreme Reagan house.',
            'category_id' => $category->id,
            'tag_ids' => [$tag->id],
            'status' => 'draft',
        ])->assertCreated()->assertJsonPath('success', true)->json('data');

        $this->assertSame('draft', $created['status']);
        $this->assertNotEmpty($created['warnings']);

        $this->actingAs($admin)->putJson('/api/v1/posts/'.$created['id'], [
            'title' => 'How Parents Can Help Their Children Develop Better Study Habits',
            'content' => '<h2>A quiet table</h2><p>The house asks families to keep a regular hour, not a lecture. Ask what the child understood, then stop.</p><p>Phones stay outside that hour. Reading beyond the set book still counts.</p>',
            'excerpt' => 'A short guide for parents in the Supreme Reagan house.',
            'category_id' => $category->id,
            'tag_ids' => [$tag->id],
            'status' => 'review',
        ])->assertOk()->assertJsonPath('data.status', 'review');

        $this->actingAs($admin)->putJson('/api/v1/posts/'.$created['id'], [
            'title' => 'How Parents Can Help Their Children Develop Better Study Habits',
            'content' => '<h2>A quiet table</h2><p>The house asks families to keep a regular hour, not a lecture. Ask what the child understood, then stop.</p><p>Phones stay outside that hour. Reading beyond the set book still counts.</p>',
            'category_id' => $category->id,
            'status' => 'published',
        ])->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('posts', ['id' => $created['id'], 'status' => 'published']);

        $this->actingAs($admin)->getJson('/api/v1/posts/'.$created['id'].'?full=1')
            ->assertOk()
            ->assertJsonPath('data.id', $created['id'])
            ->assertJsonPath('data.title', 'How Parents Can Help Their Children Develop Better Study Habits');

        $this->actingAs($admin)->putJson('/api/v1/posts/'.$created['id'], [
            'title' => 'How Parents Can Help Their Children Develop Better Study Habits',
            'content' => '<h2>A quiet table</h2><p>The house asks families to keep a regular hour.</p>',
            'category_id' => $category->id,
            'status' => 'archived',
        ])->assertOk()->assertJsonPath('data.status', 'archived');

        $this->actingAs($admin)->deleteJson('/api/v1/posts/'.$created['id'])->assertOk();
        $this->assertDatabaseMissing('posts', ['id' => $created['id']]);
    }

    public function test_schedule_releases_when_due(): void
    {
        $admin = $this->admin();
        $category = $this->newsCategory();

        $created = $this->actingAs($admin)->postJson('/api/v1/posts', [
            'title' => 'Why Reading Beyond the Classroom Matters',
            'content' => '<h2>Books after the bell</h2><p>A child who reads for pleasure keeps language alive between lessons. The house library is for that work.</p><p>Ten quiet minutes is enough to begin.</p>',
            'category_id' => $category->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated()->json('data');

        $this->assertSame('scheduled', $created['status']);
        $this->get('/news')->assertOk();
        $this->assertSame(PostStatus::Published, Post::query()->find($created['id'])?->status);
    }

    public function test_desk_paginates_articles(): void
    {
        $admin = $this->admin();
        for ($i = 1; $i <= 11; $i++) {
            $this->article(['slug' => 'desk-page-'.$i, 'title' => 'Desk page article '.$i]);
        }

        $first = $this->actingAs($admin)->getJson('/api/v1/posts?page=1')
            ->assertOk()
            ->assertJsonPath('data.meta.per_page', 10)
            ->assertJsonPath('data.meta.current_page', 1)
            ->json('data');

        $this->assertCount(10, $first['items']);
        $this->assertSame(2, $first['meta']['last_page']);

        $second = $this->actingAs($admin)->getJson('/api/v1/posts?page=2')
            ->assertOk()
            ->assertJsonPath('data.meta.current_page', 2)
            ->json('data.items');

        $this->assertNotEmpty($second);
        $this->assertNotSame($first['items'][0]['id'], $second[0]['id']);
    }

    public function test_slug_is_unique(): void
    {
        $first = $this->article(['slug' => 'choosing-the-right-secondary-school']);
        $second = $this->article([
            'title' => 'Choosing the Right Secondary School for Your Child',
            'slug' => 'choosing-the-right-secondary-school',
        ]);

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertStringStartsWith('choosing-the-right-secondary-school', $second->slug);
    }

    public function test_category_and_tag_assignment_and_protected_delete(): void
    {
        $admin = $this->admin();
        $category = $this->newsCategory('Education');
        $post = $this->article(['category_id' => $category->id]);
        $post->tags()->sync([$this->newsTag('WAEC')->id]);

        $this->actingAs($admin)->deleteJson('/api/v1/post-categories/'.$category->id)
            ->assertStatus(422);

        $this->actingAs($admin)->deleteJson('/api/v1/post-tags/'.$this->newsTag('WAEC')->id)
            ->assertStatus(422);

        $fresh = $this->actingAs($admin)->postJson('/api/v1/post-categories', [
            'name' => 'House Notes',
            'description' => 'Short notes from the office.',
            'meta_title' => 'House Notes',
            'meta_description' => 'Notes from Supreme Reagan Schools.',
        ])->assertCreated()->json('data');

        $this->actingAs($admin)->deleteJson('/api/v1/post-categories/'.$fresh['id'])->assertOk();
        $this->assertDatabaseMissing('post_categories', ['id' => $fresh['id']]);
    }

    public function test_featured_image_validation_and_xss_sanitization(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $category = $this->newsCategory();

        $this->actingAs($admin)->post('/api/v1/posts', [
            'title' => 'Benefits of Science and Technology Education',
            'content' => '<h2>Labs</h2><p>Science is a habit of asking.</p><script>alert(1)</script><p onclick="evil()">Safe paragraph.</p>',
            'category_id' => $category->id,
            'status' => 'draft',
            'featured_image' => UploadedFile::fake()->create('note.txt', 20, 'text/plain'),
        ])->assertStatus(422);

        $ok = $this->actingAs($admin)->post('/api/v1/posts', [
            'title' => 'Benefits of Science and Technology Education',
            'content' => '<h2>Labs</h2><p>Science is a habit of asking.</p><script>alert(1)</script><p>Safe paragraph.</p>',
            'category_id' => $category->id,
            'featured_image_alt' => 'A science bench',
            'status' => 'published',
            'featured_image' => UploadedFile::fake()->image('lab.jpg', 800, 450),
        ])->assertCreated()->json('data');

        $post = Post::query()->findOrFail($ok['id']);
        $this->assertStringNotContainsString('<script', (string) $post->content);
        $this->assertStringContainsString('Safe paragraph', (string) $post->content);
        $this->assertNotEmpty($post->featured_image);
        $this->assertStringStartsWith('/storage/news/', (string) $post->featured_image);
        $this->assertSame($post->featuredImageUrl(), $ok['featured_image_url'] ?? null);
        $this->actingAs($admin)->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonFragment(['featured_image_url' => $post->featuredImageUrl()]);
        $this->get($post->featuredImageUrl())->assertOk();
        $this->get($post->publicUrl())
            ->assertOk()
            ->assertSee($post->featuredImageUrl(), false);

        $html = app(EditorialHtml::class)->sanitize('<a href="javascript:alert(1)">x</a><iframe src="https://evil.test"></iframe>');
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('iframe', $html);
    }
}
