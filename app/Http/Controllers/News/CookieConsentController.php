<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class CookieConsentController extends Controller
{
    public function store(Request $request): RedirectResponse|JsonResponse
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
        $secure = $request->isSecure();
        $consent = cookie('srs_consent', $payload, $minutes, '/', null, $secure, false, false, Cookie::SAMESITE_LAX);
        $ads = $data['ads']
            ? cookie('srs_ad_consent', '1', $minutes, '/', null, $secure, false, false, Cookie::SAMESITE_LAX)
            : cookie()->forget('srs_ad_consent', '/');

        if ($request->expectsJson() || $request->ajax()) {
            return response()
                ->json([
                    'success' => true,
                    'message' => 'Cookie choices saved.',
                    'data' => json_decode($payload, true),
                ])
                ->withCookie($consent)
                ->withCookie($ads);
        }

        return back()->withCookie($consent)->withCookie($ads);
    }
}
