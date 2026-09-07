<?php

use App\Http\Controllers\Api\V1\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'role:portal'])->group(function () {
    Route::get('admins', [AdminUserController::class, 'index']);
    Route::get('admins/roles', [AdminUserController::class, 'roles']);
    Route::get('admins/permissions', [AdminUserController::class, 'permissions']);
    Route::post('admins', [AdminUserController::class, 'store']);
    Route::get('admins/{admin}', [AdminUserController::class, 'show'])->whereNumber('admin');
    Route::match(['put', 'post'], 'admins/{admin}', [AdminUserController::class, 'update'])->whereNumber('admin');
    Route::match(['put', 'post'], 'admins/{admin}/password', [AdminUserController::class, 'resetPassword'])->whereNumber('admin');
    Route::post('admins/{admin}/suspend', [AdminUserController::class, 'suspend'])->whereNumber('admin');
    Route::post('admins/{admin}/reinstate', [AdminUserController::class, 'reinstate'])->whereNumber('admin');
    Route::match(['delete', 'post'], 'admins/{admin}/remove', [AdminUserController::class, 'destroy'])->whereNumber('admin');
    Route::delete('admins/{admin}', [AdminUserController::class, 'destroy'])->whereNumber('admin');
});
