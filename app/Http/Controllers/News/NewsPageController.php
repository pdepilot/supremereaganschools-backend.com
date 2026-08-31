<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostCategory;
use App\Services\News\AdSenseService;
use App\Services\News\PostService;
use App\Services\News\RelatedPostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NewsPageController extends Controller
{
    public function __construct(
        private readonly PostService $posts,
        private readonly RelatedPostService $related,
        private readonly AdSenseService $ads,
    ) {}

    public function index(Request $request): View
    {
        $this->posts->releaseScheduled();

        $query = Post::query()->publiclyVisible()->with(['category', 'author.staffProfile', 'tags']);
        $searching = $request->filled('q') || $request->filled('tag') || $request->filled('type');

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

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn ($t) => $t->where('slug', $request->string('tag')));
        }

        if ($request->filled('type')) {
            $query->where('content_type', $request->string('type'));
        }

        $articles = $query
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->paginate(9)
            ->withQueryString();

        $categories = PostCategory::query()
            ->where('is_active', true)
            ->whereHas('posts', fn ($q) => $q->publiclyVisible())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('site.news.index', [
            'articles' => $articles,
            'popular' => $this->posts->popular(5),
            'editorsPicks' => $this->posts->editorsPicks(5),
            'categories' => $categories,
            'searching' => $searching,
            'query' => trim((string) $request->string('q')),
            'indexable' => ! $searching,
            'adsEligible' => $this->ads->mayRender($request),
        ]);
    }

    public function category(string $category): View
    {
        $this->posts->releaseScheduled();

        $row = PostCategory::query()->where('slug', $category)->where('is_active', true)->firstOrFail();

        $articles = Post::query()
            ->publiclyVisible()
            ->with(['category', 'author.staffProfile', 'tags'])
            ->where('category_id', $row->id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->paginate(9);

        return view('site.news.category', [
            'category' => $row,
            'articles' => $articles,
            'popular' => $this->posts->popular(5),
            'editorsPicks' => $this->posts->editorsPicks(5),
            'indexable' => $articles->total() > 0,
            'adsEligible' => $this->ads->mayRender(request()),
        ]);
    }

    public function show(string $category, string $slug): View|RedirectResponse
    {
        $this->posts->releaseScheduled();

        $post = Post::query()
            ->with(['category', 'author.staffProfile', 'tags'])
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless($post->isPubliclyVisible(), 404);

        if ($post->category?->slug !== $category) {
            return redirect($post->publicUrl(), 301);
        }

        $viewKey = 'news_viewed_'.$post->id;
        if (! request()->session()->has($viewKey)) {
            $post->recordPublicView();
            request()->session()->put($viewKey, true);
        }

        request()->attributes->set('article', $post);

        $related = $this->related->for($post);
        $previous = Post::query()
            ->publiclyVisible()
            ->where('category_id', $post->category_id)
            ->where('id', '!=', $post->id)
            ->where('published_at', '<', $post->published_at)
            ->orderByDesc('published_at')
            ->first();
        $next = Post::query()
            ->publiclyVisible()
            ->where('category_id', $post->category_id)
            ->where('id', '!=', $post->id)
            ->where('published_at', '>', $post->published_at)
            ->orderBy('published_at')
            ->first();

        $prepared = $this->posts->withTableOfContents((string) $post->content);

        return view('site.news.show', [
            'article' => $post,
            'articleHtml' => $prepared['html'],
            'toc' => $prepared['toc'],
            'related' => $related,
            'popular' => $this->posts->popular(5, $post->id),
            'previous' => $previous,
            'next' => $next,
            'indexable' => (bool) $post->indexable,
            'adsEligible' => $this->ads->mayRender(request(), $post),
        ]);
    }

    public function preview(Post $post): View
    {
        $this->authorize('view', $post);

        $post->load(['category', 'author.staffProfile', 'tags']);
        request()->attributes->set('article', $post);

        $prepared = $this->posts->withTableOfContents((string) $post->content);

        return view('site.news.show', [
            'article' => $post,
            'articleHtml' => $prepared['html'],
            'toc' => $prepared['toc'],
            'related' => collect(),
            'popular' => collect(),
            'previous' => null,
            'next' => null,
            'indexable' => false,
            'preview' => true,
            'adsEligible' => false,
        ]);
    }
}
