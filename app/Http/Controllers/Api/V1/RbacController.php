<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rbac\AssignUserRolesRequest;
use App\Http\Requests\Rbac\StoreRoleRequest;
use App\Http\Requests\Rbac\SyncRolePermissionsRequest;
use App\Http\Requests\Rbac\UpdateRoleRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RbacController extends Controller
{
    public function __construct(private readonly RbacService $rbac) {}

    public function roles(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        return ApiResponse::success(
            'Roles retrieved.',
            RoleResource::collection($this->rbac->roles())->resolve(),
        );
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $grouped = $this->rbac->permissions()
            ->groupBy('module')
            ->map(fn ($items, $module) => [
                'module' => $module,
                'permissions' => PermissionResource::collection($items)->resolve(),
            ])
            ->values()
            ->all();

        return ApiResponse::success('Permissions retrieved.', $grouped);
    }

    public function storeRole(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->rbac->createRole($request->validated(), $request->user());

        return ApiResponse::success('Role created.', (new RoleResource($role))->resolve(), 201);
    }

    public function showRole(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return ApiResponse::success(
            'Role retrieved.',
            (new RoleResource($role->load('permissions')->loadCount(['users', 'permissions'])))->resolve(),
        );
    }

    public function updateRole(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->rbac->updateRole($role, $request->validated(), $request->user());

        return ApiResponse::success('Role updated.', (new RoleResource($role))->resolve());
    }

    public function destroyRole(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);
        $this->rbac->deleteRole($role, request()->user());

        return ApiResponse::success('Role removed.');
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $role = $this->rbac->syncRolePermissions(
            $role,
            $request->validated('permissions') ?? [],
            $request->user(),
        );

        return ApiResponse::success('Role permissions updated.', (new RoleResource($role))->resolve());
    }

    public function assignUserRoles(AssignUserRolesRequest $request, User $user): JsonResponse
    {
        $updated = $this->rbac->assignUserRoles(
            $user,
            $request->validated('roles') ?? [],
            $request->user(),
        );

        return ApiResponse::success(
            'User roles updated.',
            (new UserResource($updated))->resolve(),
        );
    }
}
