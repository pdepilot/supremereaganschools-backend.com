<?php

namespace App\Http\Controllers\Api\V1\News;

use App\Http\Controllers\Controller;
use App\Http\Resources\News\PostCategoryResource;
use App\Http\Resources\News\PostResource;
use App\Http\Resources\News\PostTagResource;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostTag;
use App\Services\News\PostService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicNewsController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    public function index(Request $request): JsonResponse
    {
        $this->posts->releaseScheduled();

        $query = Post::query()->publiclyVisible()->with(['category', 'author', 'tags']);

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('q')).'%';
            $query->where(function ($inner) use ($term) {
                $inner->where('title', 'like', $term)
                    ->orWhere('excerpt', 'like', $term)
                    ->orWhere('content', 'like', $term)
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $term))
                    ->orWhereHas('tags', fn ($t) => $t->where('name', 'like', $term));
            });
        }

        if ($request->filled('category')) {
            $query->whereHas('category', fn ($c) => $c->where('slug', $request->string('category')));
        }

        if ($request->filled('type')) {
            $query->where('content_type', $request->string('type'));
        }

        $rows = $query->orderByDesc('is_pinned')->orderByDesc('published_at')->paginate(9);

        return ApiResponse::success('News retrieved.', [
            'items' => PostResource::collection($rows)->resolve(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $this->posts->releaseScheduled();

        $post = Post::query()
            ->publiclyVisible()
            ->with(['category', 'author', 'tags'])
            ->where('slug', $slug)
            ->firstOrFail();

        request()->merge(['full' => true]);

        return ApiResponse::success('Article retrieved.', (new PostResource($post))->resolve());
    }

    public function categories(): JsonResponse
    {
        $rows = PostCategory::query()
            ->where('is_active', true)
            ->whereHas('posts', fn ($q) => $q->publiclyVisible())
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success('Categories retrieved.', PostCategoryResource::collection($rows)->resolve());
    }

    public function tags(): JsonResponse
    {
        $rows = PostTag::query()
            ->whereHas('posts', fn ($q) => $q->publiclyVisible())
            ->orderBy('name')
            ->get();

        return ApiResponse::success('Tags retrieved.', PostTagResource::collection($rows)->resolve());
    }
}
