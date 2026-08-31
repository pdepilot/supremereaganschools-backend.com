<?php

namespace App\Services\News;

use App\Models\Post;
use App\Models\PublishingSetting;
use Illuminate\Http\Request;

class AdSenseService
{
    public function settings(): PublishingSetting
    {
        return PublishingSetting::current();
    }

    public function mayRender(Request $request, ?Post $post = null): bool
    {
        if (! $this->settings()->adsenseReady()) {
            return false;
        }

        if ($post && (! $post->ads_enabled || $post->child_directed || ! $post->isPubliclyVisible())) {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');

        foreach ([
            '/portal', '/staff', '/parent', '/student', '/login',
            '/api', '/feed', '/rss', '/sitemap.xml', '/ads.txt',
        ] as $blocked) {
            if ($path === $blocked || str_starts_with($path, $blocked.'/')) {
                return false;
            }
        }

        if ($request->user()) {
            return false;
        }

        return $this->hasAdConsent($request);
    }

    public function hasAdConsent(Request $request): bool
    {
        if ((string) $request->cookie('srs_ad_consent') === '1') {
            return true;
        }

        $raw = (string) $request->cookie('srs_consent');
        if ($raw === '') {
            return false;
        }

        $data = json_decode($raw, true);

        return is_array($data) && ! empty($data['ads']);
    }

    public function hasAnalyticsConsent(Request $request): bool
    {
        $raw = (string) $request->cookie('srs_consent');
        if ($raw === '') {
            return false;
        }

        $data = json_decode($raw, true);

        return is_array($data) && ! empty($data['analytics']);
    }

    public function clientId(): ?string
    {
        return $this->settings()->adsenseClientId();
    }
}
