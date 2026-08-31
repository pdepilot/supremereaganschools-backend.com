<?php

namespace App\Http\Controllers\Api\V1\News;

use App\Http\Controllers\Controller;
use App\Http\Resources\News\PostCategoryResource;
use App\Models\PostCategory;
use App\Services\News\PostService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PostCategoryController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', PostCategory::class);

        $rows = PostCategory::query()->orderBy('sort_order')->orderBy('name')->get();

        return ApiResponse::success('Categories retrieved.', PostCategoryResource::collection($rows)->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PostCategory::class);

        $data = $this->validated($request);
        $category = PostCategory::query()->create($data);

        return ApiResponse::success('Category created.', (new PostCategoryResource($category))->resolve(), 201);
    }

    public function update(Request $request, PostCategory $post_category): JsonResponse
    {
        $this->authorize('update', $post_category);
        $post_category->update($this->validated($request, $post_category->id));

        return ApiResponse::success('Category updated.', (new PostCategoryResource($post_category->fresh()))->resolve());
    }

    public function destroy(PostCategory $post_category): JsonResponse
    {
        $this->authorize('delete', $post_category);
        $this->posts->assertCategoryEmpty($post_category);
        $post_category->delete();

        return ApiResponse::success('Category deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('post_categories', 'name')->ignore($ignore)],
            'slug' => ['nullable', 'string', 'max:80', Rule::unique('post_categories', 'slug')->ignore($ignore)],
            'description' => ['nullable', 'string', 'max:400'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }
}
