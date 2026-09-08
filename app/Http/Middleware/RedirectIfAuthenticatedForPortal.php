<?php

namespace App\Http\Middleware;

use App\Enums\AuthPortal;
use App\Services\AuthenticationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticatedForPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST')) {
            return $next($request);
        }

        $user = $request->user();
        $portal = AuthPortal::fromLoginRequest($request);

        if ($user !== null && $portal !== null && $portal->admits($user)) {
            if ($portal === AuthPortal::Cbt && $request->session()->get(AuthenticationService::CBT_DESK_SESSION_KEY) !== true) {
                return $next($request);
            }

            return redirect()->intended(route($portal->homeRoute()));
        }

        return $next($request);
    }
}
