<?php

namespace App\Http\Requests\News;

use App\Enums\PostStatus;
use App\Enums\PostVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Post::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:180', 'unique:posts,slug'],
            'excerpt' => ['nullable', 'string', 'max:400'],
            'content' => ['required', 'string'],
            'featured_image_alt' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', Rule::enum(PostStatus::class)],
            'visibility' => ['nullable', Rule::enum(PostVisibility::class)],
            'author_id' => ['nullable', 'integer', 'exists:users,id'],
            'category_id' => ['required', 'integer', 'exists:post_categories,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:post_tags,id'],
            'published_at' => ['nullable', 'date'],
            'scheduled_at' => ['nullable', 'date'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
            'canonical_url' => ['nullable', 'url', 'max:255'],
            'og_title' => ['nullable', 'string', 'max:90'],
            'og_description' => ['nullable', 'string', 'max:200'],
            'og_image' => ['nullable', 'string', 'max:255'],
            'reading_time' => ['nullable', 'integer', 'min:1', 'max:120'],
            'allow_comments' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_pinned' => ['nullable', 'boolean'],
            'ads_enabled' => ['nullable', 'boolean'],
            'indexable' => ['nullable', 'boolean'],
            'featured_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            ...EditorialPostRules::extra(),
        ];
    }
}
