<?php

namespace App\Services\News;

use App\Enums\ContentAudience;
use App\Enums\ContentIntent;
use App\Enums\CtaDestination;
use App\Enums\CtaStrength;
use App\Enums\EducationalLevel;
use App\Enums\PostContentType;
use App\Enums\PostStatus;
use App\Enums\PostVisibility;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostTag;
use App\Models\User;
use App\Support\EditorialHtml;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PostService
{
    public function __construct(private readonly EditorialHtml $html) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $author, array $payload): Post
    {
        return DB::transaction(function () use ($author, $payload) {
            $post = new Post($this->attributes($payload, $author));
            $post->author_id = (int) ($payload['author_id'] ?? $author->id);
            $this->applyWorkflow($post, $payload);
            $post->save();
            $this->syncTags($post, $payload['tag_ids'] ?? []);

            return $post->fresh(['category', 'tags', 'author']) ?? $post;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(Post $post, array $payload): Post
    {
        return DB::transaction(function () use ($post, $payload) {
            $payload['id'] = $post->id;
            $post->fill($this->attributes($payload, $post->author));
            $this->applyWorkflow($post, $payload);
            $post->save();

            if (array_key_exists('tag_ids', $payload)) {
                $this->syncTags($post, $payload['tag_ids']);
            }

            return $post->fresh(['category', 'tags', 'author']) ?? $post;
        });
    }

    public function storeFeaturedImage(Post $post, UploadedFile $file, ?string $alt = null): Post
    {
        $directory = 'news/'.$post->id;
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'article').'-'.Str::random(6).'.'.$file->guessExtension();
        $path = $file->storeAs($directory, $name, 'public');
        $post->featured_image = '/storage/'.$path;
        $post->featured_image_alt = $alt ?: $post->title;
        $post->save();

        return $post->fresh(['category', 'tags', 'author']) ?? $post;
    }

    public function storeResourceFile(Post $post, UploadedFile $file): Post
    {
        $directory = 'news/'.$post->id.'/resources';
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'resource').'-'.Str::random(6).'.'.$file->guessExtension();
        $path = $file->storeAs($directory, $name, 'public');
        $post->resource_path = '/storage/'.$path;
        $post->resource_original_name = $file->getClientOriginalName();
        $post->save();

        return $post->fresh(['category', 'tags', 'author']) ?? $post;
    }

    public function releaseScheduled(): int
    {
        if (! Schema::hasTable('posts')) {
            return 0;
        }

        return Post::query()
            ->where('status', PostStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->update([
                'status' => PostStatus::Published,
                'published_at' => DB::raw('COALESCE(published_at, scheduled_at)'),
            ]);
    }

    /**
     * @return list<string>
     */
    public function qualityWarnings(Post $post): array
    {
        $warnings = [];
        $text = trim(strip_tags((string) $post->content));

        if (Str::length($post->title) < 12) {
            $warnings[] = 'The title is short. A clearer headline helps families find the article.';
        }

        if (Str::wordCount($text) < 80) {
            $warnings[] = 'The body is thin. Add real guidance, not a caption under a picture.';
        }

        if (blank($post->meta_description) && blank($post->excerpt)) {
            $warnings[] = 'Add an excerpt or meta description.';
        }

        if (blank($post->featured_image)) {
            $warnings[] = 'A featured image is missing.';
        }

        if (filled($post->featured_image) && blank($post->featured_image_alt)) {
            $warnings[] = 'The featured image has no alt text.';
        }

        if ($post->author_id === null) {
            $warnings[] = 'Assign an editorial author.';
        }

        if ($post->category_id === null) {
            $warnings[] = 'Assign a category.';
        }

        if (! preg_match('/<h[2-4][\s>]/i', (string) $post->content)) {
            $warnings[] = 'Add at least one heading so the article has a clear structure.';
        }

        $words = array_values(array_filter(str_word_count(strtolower($text), 1), fn (string $word) => strlen($word) > 3));
        if (count($words) > 40) {
            $freq = array_count_values($words);
            arsort($freq);
            $top = (string) array_key_first($freq);
            if ($top !== '' && ($freq[$top] / count($words)) > 0.12) {
                $warnings[] = 'The same word is repeated very often. Ease the repetition so the article reads naturally.';
            }
        }

        return $warnings;
    }

    /**
     * Editorial checklist. Usefulness over a magic word count.
     *
     * @return list<array{key: string, label: string, ok: bool}>
     */
    public function qualityChecklist(Post $post): array
    {
        $text = trim(strip_tags((string) $post->content));
        $hasInternal = (bool) preg_match('/<a\s[^>]*href=["\'][^"\']+/i', (string) $post->content);
        $cta = app(SchoolCtaResolver::class)->for($post);

        return [
            ['key' => 'title', 'label' => 'Meaningful title', 'ok' => Str::length((string) $post->title) >= 12],
            ['key' => 'introduction', 'label' => 'Useful introduction', 'ok' => filled($post->excerpt) || Str::wordCount($text) >= 40],
            ['key' => 'headings', 'label' => 'Clear headings', 'ok' => (bool) preg_match('/<h[2-4][\s>]/i', (string) $post->content)],
            ['key' => 'paragraphs', 'label' => 'Readable paragraphs', 'ok' => Str::wordCount($text) >= 80],
            ['key' => 'useful', 'label' => 'Useful information (not a caption)', 'ok' => Str::wordCount($text) >= 80],
            ['key' => 'internal_links', 'label' => 'Internal links', 'ok' => $hasInternal],
            ['key' => 'featured_image', 'label' => 'Relevant featured image', 'ok' => filled($post->featured_image)],
            ['key' => 'alt', 'label' => 'Image alt text', 'ok' => blank($post->featured_image) || filled($post->featured_image_alt)],
            ['key' => 'author', 'label' => 'Author', 'ok' => $post->author_id !== null],
            ['key' => 'published_at', 'label' => 'Publication date', 'ok' => $post->published_at !== null || $post->status !== PostStatus::Published],
            ['key' => 'updated_at', 'label' => 'Updated date when material changes', 'ok' => true],
            ['key' => 'meta_title', 'label' => 'Meta title', 'ok' => filled($post->meta_title)],
            ['key' => 'meta_description', 'label' => 'Meta description', 'ok' => filled($post->meta_description) || filled($post->excerpt)],
            ['key' => 'canonical', 'label' => 'Canonical URL', 'ok' => filled($post->canonicalUrlResolved())],
            ['key' => 'structured_data', 'label' => 'Structured data ready', 'ok' => $post->isPubliclyVisible() || $post->status !== PostStatus::Published],
            ['key' => 'cta', 'label' => 'Appropriate CTA', 'ok' => $cta !== null || ($post->cta_strength === CtaStrength::None)],
        ];
    }

    public function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'article';
        $slug = $base;
        $i = 2;

        while (Post::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attributes(array $payload, ?User $author): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $content = $this->html->sanitize((string) ($payload['content'] ?? ''));
        $excerpt = trim((string) ($payload['excerpt'] ?? ''));
        $words = max(1, Str::wordCount(strip_tags($content)));

        $metaTitle = trim((string) ($payload['meta_title'] ?? '')) ?: $title;
        $metaDescription = trim((string) ($payload['meta_description'] ?? '')) ?: Str::limit($excerpt !== '' ? $excerpt : strip_tags($content), 155, '');

        return [
            'title' => $title,
            'slug' => $this->uniqueSlug((string) ($payload['slug'] ?? $title), isset($payload['id']) ? (int) $payload['id'] : null),
            'excerpt' => $excerpt !== '' ? $excerpt : Str::limit(strip_tags($content), 220, ''),
            'content' => $content,
            'featured_image_alt' => trim((string) ($payload['featured_image_alt'] ?? '')) ?: $title,
            'visibility' => PostVisibility::tryFrom((string) ($payload['visibility'] ?? 'public')) ?? PostVisibility::Public,
            'category_id' => (int) $payload['category_id'],
            'author_id' => (int) ($payload['author_id'] ?? $author?->id),
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'canonical_url' => trim((string) ($payload['canonical_url'] ?? '')) ?: null,
            'og_title' => trim((string) ($payload['og_title'] ?? '')) ?: $metaTitle,
            'og_description' => trim((string) ($payload['og_description'] ?? '')) ?: $metaDescription,
            'og_image' => trim((string) ($payload['og_image'] ?? '')) ?: null,
            'reading_time' => (int) ($payload['reading_time'] ?? max(1, (int) ceil($words / 200))),
            'allow_comments' => (bool) ($payload['allow_comments'] ?? false),
            'is_featured' => (bool) ($payload['is_featured'] ?? false),
            'is_pinned' => (bool) ($payload['is_pinned'] ?? false),
            'ads_enabled' => array_key_exists('ads_enabled', $payload) ? (bool) $payload['ads_enabled'] : true,
            'indexable' => array_key_exists('indexable', $payload) ? (bool) $payload['indexable'] : true,
            'content_type' => PostContentType::tryFrom((string) ($payload['content_type'] ?? 'article')) ?? PostContentType::Article,
            'cta_type' => $this->nullableEnum($payload['cta_type'] ?? null, CtaDestination::class),
            'cta_strength' => CtaStrength::tryFrom((string) ($payload['cta_strength'] ?? 'standard')) ?? CtaStrength::Standard,
            'pillar_topic' => $this->nullableString($payload['pillar_topic'] ?? null),
            'supporting_topic' => $this->nullableString($payload['supporting_topic'] ?? null),
            'audience' => $this->nullableEnum($payload['audience'] ?? null, ContentAudience::class),
            'educational_level' => $this->nullableEnum($payload['educational_level'] ?? null, EducationalLevel::class),
            'intent' => $this->nullableEnum($payload['intent'] ?? null, ContentIntent::class),
            'last_reviewed_at' => $payload['last_reviewed_at'] ?? null,
            'review_due_at' => $payload['review_due_at'] ?? null,
            'is_parent_resource' => (bool) ($payload['is_parent_resource'] ?? false),
            'child_directed' => (bool) ($payload['child_directed'] ?? false),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    private function nullableEnum(mixed $value, string $enum): mixed
    {
        $raw = trim((string) $value);

        if ($raw === '' || $raw === 'auto') {
            return null;
        }

        return $enum::tryFrom($raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyWorkflow(Post $post, array $payload): void
    {
        $status = PostStatus::tryFrom((string) ($payload['status'] ?? $post->status?->value ?? 'draft')) ?? PostStatus::Draft;
        $post->status = $status;

        if ($status === PostStatus::Published) {
            $post->published_at = isset($payload['published_at']) && $payload['published_at']
                ? $payload['published_at']
                : ($post->published_at ?? now());
            $post->scheduled_at = null;
        }

        if ($status === PostStatus::Scheduled) {
            $when = $payload['scheduled_at'] ?? $payload['published_at'] ?? null;
            if ($when === null) {
                throw ValidationException::withMessages([
                    'scheduled_at' => 'A scheduled article needs a future date.',
                ]);
            }
            $post->scheduled_at = $when;
            $post->published_at = $when;
        }

        if (in_array($status, [PostStatus::Draft, PostStatus::Review, PostStatus::Archived], true) && $status !== PostStatus::Published) {
            if ($status !== PostStatus::Archived) {
                $post->published_at = $post->published_at;
            }
        }
    }

    /**
     * @param  list<int>|mixed  $tagIds
     */
    private function syncTags(Post $post, mixed $tagIds): void
    {
        $ids = Collection::wrap($tagIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $post->tags()->sync($ids->all());
    }

    public function assertCategoryEmpty(PostCategory $category): void
    {
        if ($category->posts()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Reassign the articles in this category before deleting it.',
            ]);
        }
    }

    public function assertTagUnused(PostTag $tag): void
    {
        if ($tag->posts()->exists()) {
            throw ValidationException::withMessages([
                'tag' => 'This tag is still on published or draft articles.',
            ]);
        }
    }

    /**
     * @return Collection<int, Post>
     */
    public function popular(int $limit = 5, ?int $exceptId = null): Collection
    {
        return Post::query()
            ->publiclyVisible()
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->when(Schema::hasColumn('posts', 'views_count'), fn ($query) => $query->orderByDesc('views_count'))
            ->orderByDesc('is_pinned')
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Post>
     */
    public function editorsPicks(int $limit = 5): Collection
    {
        return Post::query()
            ->publiclyVisible()
            ->where('is_featured', true)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{html: string, toc: list<array{id: string, text: string, level: int}>}
     */
    public function withTableOfContents(string $html): array
    {
        $toc = [];
        $used = [];

        $enhanced = preg_replace_callback(
            '/<h([2-3])(\s[^>]*)?>(.*?)<\/h\1>/is',
            function (array $matches) use (&$toc, &$used) {
                $level = (int) $matches[1];
                $attrs = $matches[2] ?? '';
                $inner = $matches[3];
                $text = trim(html_entity_decode(strip_tags($inner)));

                if ($text === '') {
                    return $matches[0];
                }

                $base = Str::slug($text) ?: 'section';
                $id = $base;
                $n = 2;
                while (isset($used[$id])) {
                    $id = $base.'-'.$n;
                    $n++;
                }
                $used[$id] = true;

                $toc[] = [
                    'id' => $id,
                    'text' => $text,
                    'level' => $level,
                ];

                if (preg_match('/\bid\s*=/', $attrs)) {
                    return $matches[0];
                }

                return '<h'.$level.$attrs.' id="'.e($id).'">'.$inner.'</h'.$level.'>';
            },
            $html
        ) ?? $html;

        return [
            'html' => $enhanced,
            'toc' => $toc,
        ];
    }
}
