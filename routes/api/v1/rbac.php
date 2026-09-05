<?php

use App\Enums\RoleSlug;
use App\Http\Controllers\Api\V1\RbacController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'role:'.RoleSlug::portalMiddleware()])->group(function () {
    Route::get('roles', [RbacController::class, 'roles']);
    Route::post('roles', [RbacController::class, 'storeRole']);
    Route::get('permissions', [RbacController::class, 'permissions']);
    Route::get('roles/{role}', [RbacController::class, 'showRole']);
    Route::put('roles/{role}', [RbacController::class, 'updateRole']);
    Route::delete('roles/{role}', [RbacController::class, 'destroyRole']);
    Route::put('roles/{role}/permissions', [RbacController::class, 'syncPermissions']);
    Route::put('users/{user}/roles', [RbacController::class, 'assignUserRoles']);
});
