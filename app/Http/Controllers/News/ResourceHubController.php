<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Services\News\AdSenseService;
use App\Services\News\PostService;
use App\Services\News\ResourceHubService;
use Illuminate\View\View;

class ResourceHubController extends Controller
{
    public function __construct(
        private readonly ResourceHubService $hubs,
        private readonly PostService $posts,
        private readonly AdSenseService $ads,
    ) {}

    public function index(): View
    {
        $this->posts->releaseScheduled();

        $hubs = $this->hubs->active();
        $parents = $this->hubs->parentResources(6);
        $indexable = $this->hubs->anyHubIsIndexable();

        return view('site.resources.index', [
            'hubs' => $hubs,
            'parents' => $parents,
            'indexable' => $indexable,
            'adsEligible' => $this->ads->mayRender(request()),
        ]);
    }

    public function show(string $hub): View
    {
        $this->posts->releaseScheduled();

        $row = $this->hubs->findActive($hub);
        abort_if($row === null, 404);

        $featured = $this->hubs->featured($row);
        $latest = $this->hubs->latest($row, 9, $featured->pluck('id')->all());
        $categories = $this->hubs->relatedCategories($row);

        return view('site.resources.hub', [
            'hub' => $row,
            'featured' => $featured,
            'latest' => $latest,
            'categories' => $categories,
            'indexable' => $row->isIndexable(),
            'adsEligible' => $this->ads->mayRender(request()),
        ]);
    }
}
