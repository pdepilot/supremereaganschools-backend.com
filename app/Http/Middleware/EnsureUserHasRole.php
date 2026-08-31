<?php

namespace App\Http\Middleware;

use App\Enums\AuthPortal;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null, 401);

        if ($user->status !== UserStatus::Active) {
            $this->endSession($request);

            return $this->reject($request);
        }

        $allowed = array_map(
            fn (string $role) => RoleSlug::from($role),
            $roles
        );

        if ($user->hasAnyRole(...$allowed)) {
            return $next($request);
        }

        return $this->reject($request, $user);
    }

    private function reject(Request $request, ?User $user = null): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            abort(403);
        }

        $home = AuthPortal::forUser($user);

        if ($home !== null) {
            return redirect()->route($home->homeRoute());
        }

        return redirect()->guest(route(AuthPortal::matchingRequest($request)->loginRoute()));
    }

    private function endSession(Request $request): void
    {
        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
