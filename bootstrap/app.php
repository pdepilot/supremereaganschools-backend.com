<?php

use App\Enums\AuthPortal;
use App\Enums\RoleSlug;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\RedirectIfAuthenticatedForPortal;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            'srs_consent',
            'srs_ad_consent',
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'guest.portal' => RedirectIfAuthenticatedForPortal::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('staff') || $request->is('staff/*')) {
                return route('staff.login');
            }

            if ($request->is('parent') || $request->is('parent/*')) {
                return route('parent.login');
            }

            if ($request->is('student') || $request->is('student/*')) {
                return route('student.login');
            }

            return route('login');
        });

        $middleware->redirectUsersTo(function () {
            $user = Auth::user();

            if (! $user instanceof User) {
                return route('login');
            }

            if ($user->hasAnyRole(...AuthPortal::Portal->allowedRoles())) {
                return route('portal.home');
            }

            if ($user->hasAnyRole(...AuthPortal::Staff->allowedRoles())) {
                return route('staff.home');
            }

            if ($user->hasRole(RoleSlug::Parent)) {
                return route('parent.home');
            }

            if ($user->hasRole(RoleSlug::Student)) {
                return route('student.home');
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                $e->getMessage(),
                $e->errors(),
                $e->status,
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error('Unauthenticated.', status: 401);
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException|HttpException $e, Request $request) {
            $status = $e instanceof HttpException ? $e->getStatusCode() : 403;

            if ($status !== 403) {
                return null;
            }

            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error('This action is unauthorized.', status: 403);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error('The requested resource was not found.', status: 404);
        });
    })->create();
