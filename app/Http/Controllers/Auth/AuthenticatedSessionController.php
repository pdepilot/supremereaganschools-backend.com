<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthenticationService;
use App\Support\ApiResponse;
use App\Support\FrontendPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly FrontendPage $frontend,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        return $this->loginPage($request, AuthPortal::Portal, 'superAdminLogin.html', 'auth');
    }

    public function staffLogin(Request $request): Response|RedirectResponse
    {
        return $this->loginPage($request, AuthPortal::Staff, 'auth/staffLogin.html', 'auth');
    }

    public function parentLogin(Request $request): Response|RedirectResponse
    {
        return $this->loginPage($request, AuthPortal::Parent, 'auth/Parent_studentlogin.html', 'auth');
    }

    public function studentLogin(Request $request): Response|RedirectResponse
    {
        return $this->loginPage($request, AuthPortal::Student, 'auth/parent_studentPage.html', 'auth');
    }

    public function store(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $user = $this->authentication->login(
            $request->validated('email'),
            $request->validated('admission_number'),
            $request->validated('password'),
            $request->portal(),
            $request->remember(),
            $this->authentication->throttleKey($request->throttleIdentifier(), $request->ip() ?? '0.0.0.0'),
        );

        $redirect = $this->pathAfterAuthentication($request->portal(), $request);

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

        return redirect()->route('login');
    }

    public function portalHome(Request $request): JsonResponse|Response
    {
        return $this->home($request, AuthPortal::Portal);
    }

    public function staffHome(Request $request): JsonResponse|Response
    {
        return $this->home($request, AuthPortal::Staff);
    }

    public function parentHome(Request $request): JsonResponse|Response
    {
        return $this->home($request, AuthPortal::Parent);
    }

    public function studentHome(Request $request): JsonResponse|Response
    {
        return $this->home($request, AuthPortal::Student);
    }

    public function home(Request $request, AuthPortal $portal): JsonResponse|Response
    {
        $user = $request->user()->load('roles.permissions');

        if ($request->expectsJson()) {
            return ApiResponse::success('Authenticated.', [
                'user' => (new UserResource($user))->resolve(),
                'portal' => $portal->value,
            ]);
        }

        return match ($portal) {
            AuthPortal::Portal => $this->frontend->response('admin/dashboard.html', area: 'admin'),
            AuthPortal::Staff => $this->frontend->response('staff/staff.html', area: 'staff'),
            AuthPortal::Parent => $this->frontend->response('parent_student/dashboard.html', area: 'parent'),
            AuthPortal::Student => $this->frontend->response('parent_student/student_dashboard.html', area: 'student'),
            AuthPortal::Cbt => $this->frontend->response('cbt/desk.html', area: 'auth'),
        };
    }

    private function loginPage(Request $request, AuthPortal $portal, string $file, string $area): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user && $portal->admits($user)) {
            return redirect()->to($this->pathAfterAuthentication($portal, $request));
        }

        return $this->frontend->response($file, [
            '{{CSRF_TOKEN}}' => csrf_token(),
        ], $area);
    }

    private function pathAfterAuthentication(AuthPortal $portal, Request $request): string
    {
        $user = $request->user();

        if ($portal === AuthPortal::Staff && $user?->must_change_password) {
            if ($request->hasSession()) {
                $request->session()->forget('url.intended');
            }

            return '/staff/settings';
        }

        return $portal->consumeIntendedPath($request);
    }
}
