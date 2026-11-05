<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admins\ResetAdminPasswordRequest;
use App\Http\Requests\Admins\StoreAdminUserRequest;
use App\Http\Requests\Admins\UpdateAdminUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Services\AdminUserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function __construct(private readonly AdminUserService $admins) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->only(['search', 'role', 'status']);
        $perPage = max(1, min(100, $request->integer('per_page', 10)));

        if ($request->boolean('all') || $request->input('paginate') === '0') {
            return ApiResponse::success(
                'Admin users retrieved.',
                AdminUserResource::collection($this->admins->list($filters))->resolve(),
            );
        }

        $paginator = $this->admins->paginate($filters, $perPage);

        return ApiResponse::paginated(
            'Admin users retrieved.',
            AdminUserResource::collection($paginator->items())->resolve(),
            $paginator,
            200,
            ['summary' => $this->admins->summary()]
        );
    }

    public function roles(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return ApiResponse::success(
            'Appointable roles retrieved.',
            RoleResource::collection($this->admins->appointableRoles())->resolve(),
        );
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $grouped = $this->admins->appointablePermissions()
            ->groupBy('module')
            ->map(fn ($items, $module) => [
                'module' => $module,
                'permissions' => PermissionResource::collection($items)->resolve(),
            ])
            ->values()
            ->all();

        return ApiResponse::success('Appointable permissions retrieved.', $grouped);
    }

    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $admin = $this->admins->create($request->validated(), $request->user());

        return ApiResponse::success(
            'Admin user created.',
            (new AdminUserResource($admin))->resolve(),
            201,
        );
    }

    public function show(User $admin): JsonResponse
    {
        $this->admins->assertManagedDeskUser($admin);
        $this->authorize('view', $admin);

        return ApiResponse::success(
            'Admin user retrieved.',
            $this->admins->dossier($admin),
        );
    }

    public function update(UpdateAdminUserRequest $request, User $admin): JsonResponse
    {
        $this->admins->assertManagedDeskUser($admin);

        $updated = $this->admins->update($admin, $request->validated(), $request->user());

        return ApiResponse::success(
            'Admin user updated.',
            (new AdminUserResource($updated))->resolve(),
        );
    }

    public function resetPassword(ResetAdminPasswordRequest $request, User $admin): JsonResponse
    {
        $this->admins->assertManagedDeskUser($admin);

        $updated = $this->admins->changePassword(
            $admin,
            $request->validated('password'),
            $request->user(),
        );

        return ApiResponse::success(
            'Admin password reset.',
            (new AdminUserResource($updated))->resolve(),
        );
    }

    public function suspend(Request $request, User $admin): JsonResponse
    {
        $this->admins->assertManagedDeskUser($admin);
        $this->authorize('suspend', $admin);

        return ApiResponse::success(
            'Admin user suspended.',
            (new AdminUserResource($this->admins->suspend($admin, $request->user())))->resolve(),
        );
    }

    public function reinstate(Request $request, User $admin): JsonResponse
    {
        $this->admins->assertManagedDeskUser($admin);
        $this->authorize('reinstate', $admin);

        return ApiResponse::success(
            'Admin user reactivated.',
            (new AdminUserResource($this->admins->reinstate($admin, $request->user())))->resolve(),
        );
    }

    public function destroy(Request $request, User $admin): JsonResponse
    {
        $this->admins->assertManagedDeskUser($admin);
        $this->authorize('delete', $admin);
        $this->admins->delete($admin, $request->user());

        return ApiResponse::success('Admin user removed from the desk.');
    }
}
