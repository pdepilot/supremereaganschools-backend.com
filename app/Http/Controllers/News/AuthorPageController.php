<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use App\Services\News\AdSenseService;
use App\Services\News\PostService;
use Illuminate\View\View;

class AuthorPageController extends Controller
{
    public function __construct(
        private readonly PostService $posts,
        private readonly AdSenseService $ads,
    ) {}

    public function show(User $user): View
    {
        $this->posts->releaseScheduled();

        $articles = Post::query()
            ->publiclyVisible()
            ->with(['category', 'author.staffProfile', 'author.authorProfile'])
            ->where('author_id', $user->id)
            ->orderByDesc('published_at')
            ->paginate(9);

        abort_if($articles->total() === 0, 404);

        $user->load(['staffProfile', 'authorProfile']);

        return view('site.news.author', [
            'author' => $user,
            'articles' => $articles,
            'indexable' => true,
            'adsEligible' => $this->ads->mayRender(request()),
        ]);
    }
}
