<?php

namespace App\Http\Middleware;

use App\Enums\AuthPortal;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuthenticationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $desk = $this->deskForRoles($roles, $request);
        $user = $this->userForDesk($request, $desk);

        abort_if($user === null, 401);

        if ($user->status !== UserStatus::Active) {
            $this->endSession($request);

            return $this->reject($request);
        }

        if ($roles === ['portal'] || (count($roles) === 1 && ($roles[0] ?? null) === 'portal')) {
            if (AuthPortal::Portal->admits($user)) {
                AuthPortal::Portal->forgetForeignIntended($request);

                return $next($request);
            }

            return $this->reject($request, $user);
        }

        if ($roles === ['cbt'] || (count($roles) === 1 && ($roles[0] ?? null) === 'cbt')) {
            if (AuthPortal::Cbt->admits($user)) {
                AuthPortal::Cbt->forgetForeignIntended($request);

                return $next($request);
            }

            return $this->reject($request, $user);
        }

        $allowed = array_map(
            fn (string $role) => RoleSlug::from($role),
            $roles
        );

        if ($user->hasAnyRole(...$allowed)) {
            $desk->forgetForeignIntended($request);

            return $next($request);
        }

        return $this->reject($request, $user);
    }

    /**
     * @param  list<string>  $roles
     */
    private function deskForRoles(array $roles, Request $request): AuthPortal
    {
        if ($roles === ['portal'] || (count($roles) === 1 && ($roles[0] ?? null) === 'portal')) {
            return AuthPortal::Portal;
        }

        if ($roles === ['cbt'] || (count($roles) === 1 && ($roles[0] ?? null) === 'cbt')) {
            return AuthPortal::Cbt;
        }

        if ($roles === ['parent'] || (count($roles) === 1 && ($roles[0] ?? null) === 'parent')) {
            return AuthPortal::Parent;
        }

        if ($roles === ['student'] || (count($roles) === 1 && ($roles[0] ?? null) === 'student')) {
            return AuthPortal::Student;
        }

        return AuthPortal::matchingRequest($request);
    }

    private function userForDesk(Request $request, AuthPortal $desk): ?User
    {
        $user = $request->user();

        if ($user instanceof User && $user->status === UserStatus::Active && $desk->admits($user)) {
            return $user;
        }

        $restored = app(AuthenticationService::class)->activateDeskSession($desk);

        return $restored ?? ($user instanceof User ? $user : null);
    }

    private function reject(Request $request, ?User $user = null): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            abort(403);
        }

        $home = AuthPortal::forUser($user);

        if ($home !== null) {
            return redirect()
                ->route($home->homeRoute())
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        return redirect()
            ->guest(route(AuthPortal::matchingRequest($request)->loginRoute()))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    private function endSession(Request $request): void
    {
        app(AuthenticationService::class)->clearDeskSessions();
        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
