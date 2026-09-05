<?php

use App\Enums\RoleSlug;
use App\Http\Controllers\Api\V1\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'role:'.RoleSlug::portalMiddleware()])->group(function () {
    Route::get('admins', [AdminUserController::class, 'index']);
    Route::get('admins/roles', [AdminUserController::class, 'roles']);
    Route::post('admins', [AdminUserController::class, 'store']);
    Route::get('admins/{admin}', [AdminUserController::class, 'show'])->whereNumber('admin');
    Route::put('admins/{admin}', [AdminUserController::class, 'update'])->whereNumber('admin');
    Route::put('admins/{admin}/password', [AdminUserController::class, 'resetPassword'])->whereNumber('admin');
    Route::post('admins/{admin}/suspend', [AdminUserController::class, 'suspend'])->whereNumber('admin');
    Route::post('admins/{admin}/reinstate', [AdminUserController::class, 'reinstate'])->whereNumber('admin');
    Route::delete('admins/{admin}', [AdminUserController::class, 'destroy'])->whereNumber('admin');
});
