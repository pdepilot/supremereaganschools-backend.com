<?php

namespace App\Http\Controllers\Api\V1\News;

use App\Http\Controllers\Controller;
use App\Http\Requests\News\StorePostRequest;
use App\Http\Requests\News\UpdatePostRequest;
use App\Http\Resources\News\PostResource;
use App\Models\Post;
use App\Services\News\PostService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        $rows = Post::query()
            ->with(['category', 'tags', 'author'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('content_type'), fn ($q) => $q->where('content_type', $request->string('content_type')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('title', 'like', $term)->orWhere('excerpt', 'like', $term));
            })
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->paginate(10);

        return ApiResponse::success('Articles retrieved.', [
            'items' => PostResource::collection($rows)->resolve(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
                'total' => $rows->total(),
            ],
            'summary' => $this->editorialSummary(),
        ]);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        if (in_array($request->input('status'), ['published', 'scheduled'], true)) {
            $this->authorize('publish', Post::class);
        }

        $post = $this->posts->create($request->user(), $request->validated());

        if ($request->hasFile('featured_image')) {
            $post = $this->posts->storeFeaturedImage($post, $request->file('featured_image'), $request->input('featured_image_alt'));
        }

        if ($request->hasFile('resource_file')) {
            $post = $this->posts->storeResourceFile($post, $request->file('resource_file'));
        }

        return ApiResponse::success('Article saved.', (new PostResource($post))->resolve(), 201);
    }

    public function show(Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        return ApiResponse::success('Article retrieved.', (new PostResource($post->load(['category', 'tags', 'author'])))->resolve());
    }

    public function update(UpdatePostRequest $request, Post $post): JsonResponse
    {
        if (in_array($request->input('status'), ['published', 'scheduled'], true)) {
            $this->authorize('publish', Post::class);
        }

        $updated = $this->posts->update($post, $request->validated());

        if ($request->hasFile('featured_image')) {
            $updated = $this->posts->storeFeaturedImage($updated, $request->file('featured_image'), $request->input('featured_image_alt'));
        }

        if ($request->hasFile('resource_file')) {
            $updated = $this->posts->storeResourceFile($updated, $request->file('resource_file'));
        }

        return ApiResponse::success('Article updated.', (new PostResource($updated))->resolve());
    }

    public function destroy(Post $post): JsonResponse
    {
        $this->authorize('delete', $post);
        $post->tags()->detach();
        $post->delete();

        return ApiResponse::success('Article deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function editorialSummary(): array
    {
        $published = Post::query()->publiclyVisible();

        return [
            'total' => Post::query()->count(),
            'published' => (clone $published)->count(),
            'drafts' => Post::query()->where('status', 'draft')->count(),
            'review' => Post::query()->where('status', 'review')->count(),
            'scheduled' => Post::query()->where('status', 'scheduled')->count(),
            'featured' => Post::query()->where('is_featured', true)->count(),
            'needs_seo' => Post::query()->where(function ($q) {
                $q->whereNull('meta_description')->orWhere('meta_description', '');
            })->count(),
            'needs_image' => Post::query()->where(function ($q) {
                $q->whereNull('featured_image')->orWhere('featured_image', '');
            })->count(),
            'needs_review' => Post::query()
                ->where('status', 'published')
                ->whereNotNull('review_due_at')
                ->where('review_due_at', '<=', now())
                ->count(),
            'by_category' => Post::query()
                ->selectRaw('category_id, count(*) as total')
                ->groupBy('category_id')
                ->pluck('total', 'category_id'),
            'by_content_type' => Post::query()
                ->selectRaw('content_type, count(*) as total')
                ->groupBy('content_type')
                ->pluck('total', 'content_type'),
            'calendar' => Post::query()
                ->where('status', 'scheduled')
                ->orderBy('scheduled_at')
                ->limit(12)
                ->get(['id', 'title', 'scheduled_at', 'status'])
                ->map(fn (Post $post) => [
                    'id' => $post->id,
                    'title' => $post->title,
                    'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                ]),
        ];
    }
}
