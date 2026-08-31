<?php

namespace App\Http\Middleware;

use App\Enums\AuthPortal;
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
            return redirect()->intended(route($portal->homeRoute()));
        }

        return $next($request);
    }
}
