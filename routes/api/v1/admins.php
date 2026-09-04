<?php

use App\Http\Controllers\Api\V1\AdminAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::middleware('role:super_admin,school_admin')->group(function () {
        Route::get('admins', [AdminAccountController::class, 'index']);
        Route::get('admins/permissions', [AdminAccountController::class, 'permissions']);
        Route::get('admins/{admin}', [AdminAccountController::class, 'show']);
        Route::put('admins/{admin}', [AdminAccountController::class, 'update']);
    });

    Route::middleware('role:super_admin')->group(function () {
        Route::post('admins', [AdminAccountController::class, 'store']);
        Route::post('admins/{admin}/suspend', [AdminAccountController::class, 'suspend']);
        Route::post('admins/{admin}/reinstate', [AdminAccountController::class, 'reinstate']);
        Route::delete('admins/{admin}', [AdminAccountController::class, 'destroy']);
    });
});
