<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ResourceDownloadController extends Controller
{
    public function show(string $category, string $slug): BinaryFileResponse|RedirectResponse
    {
        $post = Post::query()
            ->with('category')
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless($post->isPubliclyVisible() && $post->hasDownloadableResource(), 404);

        if ($post->category?->slug !== $category) {
            return redirect($post->resourceDownloadUrl() ?? $post->publicUrl(), 301);
        }

        $relative = (string) $post->resource_path;
        abort_unless(str_starts_with($relative, '/storage/'), 404);

        $path = storage_path('app/public/'.ltrim(substr($relative, strlen('/storage/')), '/'));
        abort_unless(is_file($path), 404);

        $name = $post->resource_original_name ?: basename($path);

        return response()->download($path, $name);
    }
}
