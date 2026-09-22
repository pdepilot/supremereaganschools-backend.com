<?php

namespace App\Http\Middleware;

use App\Services\AuthenticationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActivateDeskSession
{
    public function __construct(private readonly AuthenticationService $authentication) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldActivate($request)) {
            $this->authentication->activateDeskForRequest($request);
        }

        return $next($request);
    }

    private function shouldActivate(Request $request): bool
    {
        if ($request->is('api', 'api/*')) {
            return false;
        }

        if (preg_match('#(^|/)(login|forgot-password|reset-password)(/|$)#', $request->path()) === 1) {
            return false;
        }

        return $request->is(
            'portal',
            'portal/*',
            'staff',
            'staff/*',
            'parent',
            'parent/*',
            'student',
            'student/*',
            'cbt',
            'cbt/*',
            'admin',
            'admin/*',
        );
    }
}
