<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CookieConsentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ads' => ['required', 'boolean'],
            'analytics' => ['required', 'boolean'],
        ]);

        $payload = json_encode([
            'necessary' => true,
            'ads' => (bool) $data['ads'],
            'analytics' => (bool) $data['analytics'],
        ], JSON_THROW_ON_ERROR);

        $minutes = 60 * 24 * 180;
        $consent = cookie('srs_consent', $payload, $minutes, '/', null, false, false, false, 'lax');
        $ads = $data['ads']
            ? cookie('srs_ad_consent', '1', $minutes, '/', null, false, false, false, 'lax')
            : cookie()->forget('srs_ad_consent');

        return back()->withCookie($consent)->withCookie($ads);
    }
}
