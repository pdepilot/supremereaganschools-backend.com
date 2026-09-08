<?php

namespace App\Http\Middleware;

use App\Services\AuthenticationService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCbtDeskSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get(AuthenticationService::CBT_DESK_SESSION_KEY) === true) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return ApiResponse::error('Sign in at the CBT desk to continue.', status: 401);
        }

        return redirect()->guest(route('cbt.login'));
    }
}
