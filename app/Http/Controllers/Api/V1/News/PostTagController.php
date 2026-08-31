<?php

namespace App\Http\Controllers\Api\V1\News;

use App\Http\Controllers\Controller;
use App\Http\Resources\News\PostTagResource;
use App\Models\PostTag;
use App\Services\News\PostService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PostTagController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', PostTag::class);

        return ApiResponse::success('Tags retrieved.', PostTagResource::collection(PostTag::query()->orderBy('name')->get())->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PostTag::class);
        $tag = PostTag::query()->create($this->validated($request));

        return ApiResponse::success('Tag created.', (new PostTagResource($tag))->resolve(), 201);
    }

    public function update(Request $request, PostTag $post_tag): JsonResponse
    {
        $this->authorize('update', $post_tag);
        $post_tag->update($this->validated($request, $post_tag->id));

        return ApiResponse::success('Tag updated.', (new PostTagResource($post_tag->fresh()))->resolve());
    }

    public function destroy(PostTag $post_tag): JsonResponse
    {
        $this->authorize('delete', $post_tag);
        $this->posts->assertTagUnused($post_tag);
        $post_tag->delete();

        return ApiResponse::success('Tag deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('post_tags', 'name')->ignore($ignore)],
            'slug' => ['nullable', 'string', 'max:80', Rule::unique('post_tags', 'slug')->ignore($ignore)],
            'description' => ['nullable', 'string', 'max:400'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        return $data;
    }
}
