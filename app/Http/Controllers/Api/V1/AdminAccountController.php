<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admins\StoreAdminAccountRequest;
use App\Http\Requests\Admins\UpdateAdminAccountRequest;
use App\Http\Resources\AdminAccountResource;
use App\Http\Resources\PermissionResource;
use App\Models\User;
use App\Services\AdminAccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAccountController extends Controller
{
    public function __construct(private readonly AdminAccountService $admins) {}

    public function index(Request $request): JsonResponse
    {
        $this->admins->assertCanManageAccounts($request->user());

        return ApiResponse::success(
            'Admin accounts retrieved.',
            AdminAccountResource::collection($this->admins->list())->resolve(),
        );
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->admins->assertSuperAdmin($request->user());

        return ApiResponse::success(
            'Desk permissions retrieved.',
            PermissionResource::collection($this->admins->permissionCatalogue())->resolve(),
        );
    }

    public function store(StoreAdminAccountRequest $request): JsonResponse
    {
        $admin = $this->admins->create($request->validated(), $request->user());

        return ApiResponse::success(
            'Admin account created.',
            (new AdminAccountResource($admin))->resolve(),
            201,
        );
    }

    public function show(Request $request, User $admin): JsonResponse
    {
        $this->admins->assertCanManageAccounts($request->user());
        abort_unless($admin->hasAnyRole(\App\Enums\RoleSlug::SuperAdmin, \App\Enums\RoleSlug::SchoolAdmin), 404);

        return ApiResponse::success(
            'Admin account retrieved.',
            (new AdminAccountResource($admin->load($this->admins->defaultRelations())))->resolve(),
        );
    }

    public function update(UpdateAdminAccountRequest $request, User $admin): JsonResponse
    {
        $updated = $this->admins->update($admin, $request->validated(), $request->user());

        return ApiResponse::success(
            'Admin account updated.',
            (new AdminAccountResource($updated))->resolve(),
        );
    }

    public function suspend(Request $request, User $admin): JsonResponse
    {
        return ApiResponse::success(
            'Admin account suspended.',
            (new AdminAccountResource($this->admins->suspend($admin, $request->user())))->resolve(),
        );
    }

    public function reinstate(Request $request, User $admin): JsonResponse
    {
        return ApiResponse::success(
            'Admin account reinstated.',
            (new AdminAccountResource($this->admins->reinstate($admin, $request->user())))->resolve(),
        );
    }

    public function destroy(Request $request, User $admin): JsonResponse
    {
        $this->admins->delete($admin, $request->user());

        return ApiResponse::success('Admin account removed.');
    }
}
