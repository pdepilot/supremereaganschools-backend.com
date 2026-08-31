<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\PasswordResetService;
use App\Support\ApiResponse;
use App\Support\FrontendPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $resets,
        private readonly FrontendPage $frontend,
    ) {}

    public function requestForm(Request $request): Response|RedirectResponse
    {
        $portal = AuthPortal::matchingRequest($request);
        abort_unless($portal->supportsEmailPassword(), 404);

        return $this->page($request, $portal, 'forgot');
    }

    public function resetForm(Request $request, string $token): Response|RedirectResponse
    {
        $portal = AuthPortal::matchingRequest($request);
        abort_unless($portal->supportsEmailPassword(), 404);

        return $this->page($request, $portal, 'reset', $token);
    }

    public function send(ForgotPasswordRequest $request): JsonResponse|RedirectResponse
    {
        $this->resets->send($request->validated('email'), $request->portal());

        $redirect = route($request->portal()->loginRoute(), absolute: false);

        if ($request->expectsJson()) {
            return ApiResponse::success(PasswordResetService::SENT, [
                'redirect' => $redirect,
            ]);
        }

        return back()->with('status', PasswordResetService::SENT);
    }

    public function update(ResetPasswordRequest $request): JsonResponse|RedirectResponse
    {
        $this->resets->reset(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
            $request->portal(),
        );

        $redirect = route($request->portal()->loginRoute(), absolute: false);

        if ($request->expectsJson()) {
            return ApiResponse::success(PasswordResetService::RESET, [
                'redirect' => $redirect,
            ]);
        }

        return redirect($redirect)->with('status', PasswordResetService::RESET);
    }

    private function page(Request $request, AuthPortal $portal, string $kind, ?string $token = null): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user && $portal->admits($user)) {
            return redirect()->intended(route($portal->homeRoute()));
        }

        $file = $portal === AuthPortal::Portal
            ? 'auth/admin-'.$kind.'-password.html'
            : 'auth/'.$kind.'-password.html';

        return $this->frontend->response($file, [
            '{{CSRF_TOKEN}}' => csrf_token(),
            '{{PORTAL}}' => $portal->value,
            '{{BODY_CLASS}}' => $portal === AuthPortal::Parent ? 'parent-auth' : 'staff-auth',
            '{{DESK}}' => $portal->deskName(),
            '{{LOGIN_HREF}}' => route($portal->loginRoute(), absolute: false),
            '{{TOKEN}}' => e((string) $token),
            '{{EMAIL}}' => e((string) $request->query('email', '')),
        ], 'auth');
    }
}
