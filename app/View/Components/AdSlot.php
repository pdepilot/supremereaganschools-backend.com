<?php

namespace App\View\Components;

use App\Models\Post;
use App\Services\News\AdSenseService;
use Illuminate\View\Component;
use Illuminate\View\View;

class AdSlot extends Component
{
    public function __construct(public string $position) {}

    public function render(): View|string
    {
        $ads = app(AdSenseService::class);
        $article = request()->attributes->get('article');
        $post = $article instanceof Post ? $article : null;

        if (! $ads->mayRender(request(), $post)) {
            return '';
        }

        return view('components.ad-slot', [
            'position' => $this->position,
            'client' => $ads->clientId(),
            'auto' => $ads->settings()->adsenseAutoAds(),
        ]);
    }
}
