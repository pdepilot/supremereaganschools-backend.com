<?php

namespace App\Http\Controllers\Cbt;

use App\Enums\AuthPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthenticationService;
use App\Services\Cbt\CbtAccessService;
use App\Support\ApiResponse;
use App\Support\FrontendPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CbtAuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly CbtAccessService $access,
        private readonly FrontendPage $frontend,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->authentication->hasCbtDeskSession()) {
            $user = $request->user();

            if ($user && AuthPortal::Cbt->admits($user)) {
                return redirect()->to($this->access->homePath($user));
            }
        }

        return $this->frontend->response('cbt/login.html', [
            '{{CSRF_TOKEN}}' => csrf_token(),
        ], 'auth');
    }

    public function store(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $request->merge(['portal' => AuthPortal::Cbt->value]);

        $user = $this->authentication->login(
            $request->validated('email'),
            $request->validated('admission_number'),
            $request->validated('password'),
            AuthPortal::Cbt,
            $request->remember(),
            $this->authentication->throttleKey($request->throttleIdentifier(), $request->ip() ?? '0.0.0.0'),
        );

        $redirect = $this->access->homePath($user);

        if ($request->expectsJson()) {
            return ApiResponse::success('Signed in successfully.', [
                'user' => (new UserResource($user->load('roles.permissions')))->resolve(),
                'redirect' => $redirect,
            ]);
        }

        return redirect()->to($redirect);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $this->authentication->logout();

        if ($request->expectsJson()) {
            return ApiResponse::success('Signed out successfully.');
        }

        return redirect()->route('cbt.login');
    }

    public function home(Request $request): Response|JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canEnter($user), 403);

        if ($this->access->canManage($user) || $this->access->canMark($user) || $this->access->canProctor($user)) {
            if (! $this->access->isStudentTaker($user)) {
                return redirect()->route('cbt.admin.home');
            }
        }

        if ($request->expectsJson()) {
            return ApiResponse::success('CBT desk.', [
                'user' => (new UserResource($user->load('roles.permissions')))->resolve(),
                'portal' => AuthPortal::Cbt->value,
                'desk' => 'student',
            ]);
        }

        return $this->frontend->response('cbt/desk.html', [
            '{{CSRF_TOKEN}}' => csrf_token(),
        ], 'auth');
    }

    public function adminHome(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user && ($this->access->canManage($user) || $this->access->canMark($user) || $this->access->canProctor($user)),
            403,
        );

        if ($request->expectsJson()) {
            return ApiResponse::success('CBT admin desk.', [
                'user' => (new UserResource($user->load('roles.permissions')))->resolve(),
                'portal' => AuthPortal::Cbt->value,
                'desk' => 'admin',
                'capabilities' => [
                    'manage' => $this->access->canManage($user),
                    'mark' => $this->access->canMark($user),
                    'proctor' => $this->access->canProctor($user),
                ],
            ]);
        }

        return $this->frontend->response('cbt/admin.html', [
            '{{CSRF_TOKEN}}' => csrf_token(),
        ], 'auth');
    }
}
