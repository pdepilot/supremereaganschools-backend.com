<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('attendance/offerings', [AttendanceController::class, 'offerings']);
    Route::get('attendance/register', [AttendanceController::class, 'register']);
    Route::get('attendance/summary', [AttendanceController::class, 'summary']);
    Route::get('attendance', [AttendanceController::class, 'index']);
    Route::get('attendance/{attendance_record}', [AttendanceController::class, 'show']);
    Route::post('attendance', [AttendanceController::class, 'store']);
    Route::post('attendance/bulk', [AttendanceController::class, 'bulk']);
    Route::put('attendance/{attendance_record}', [AttendanceController::class, 'update']);
    Route::delete('attendance/{attendance_record}', [AttendanceController::class, 'destroy']);
});
