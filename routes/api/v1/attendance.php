<?php

use App\Http\Controllers\Api\V1\AdminStaffAttendanceController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\StaffAttendanceController;
use App\Http\Controllers\Api\V1\StaffCampusAssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    // Pupil class attendance
    Route::get('attendance/offerings', [AttendanceController::class, 'offerings']);
    Route::get('attendance/register', [AttendanceController::class, 'register']);
    Route::get('attendance/summary', [AttendanceController::class, 'summary']);
    Route::get('attendance', [AttendanceController::class, 'index']);
    Route::get('attendance/{attendance_record}', [AttendanceController::class, 'show']);
    Route::post('attendance', [AttendanceController::class, 'store']);
    Route::post('attendance/bulk', [AttendanceController::class, 'bulk']);
    Route::put('attendance/{attendance_record}', [AttendanceController::class, 'update']);
    Route::delete('attendance/{attendance_record}', [AttendanceController::class, 'destroy']);

    // Staff GPS attendance (personal)
    Route::prefix('staff/attendance')->group(function () {
        Route::get('overview', [StaffAttendanceController::class, 'overview']);
        Route::post('clock-in', [StaffAttendanceController::class, 'clockIn']);
        Route::post('clock-out', [StaffAttendanceController::class, 'clockOut']);
        Route::get('history', [StaffAttendanceController::class, 'history']);
        Route::post('correction', [StaffAttendanceController::class, 'correction']);
    });

    // Admin staff GPS attendance command desk
    Route::prefix('admin/staff-attendance')->group(function () {
        Route::get('overview', [AdminStaffAttendanceController::class, 'overview']);
        Route::get('records', [AdminStaffAttendanceController::class, 'records']);
        Route::get('logs', [AdminStaffAttendanceController::class, 'verificationLogs']);
        Route::get('adjustments', [AdminStaffAttendanceController::class, 'adjustments']);
        Route::post('adjustments/{adjustment}/review', [AdminStaffAttendanceController::class, 'reviewAdjustment']);
        Route::post('manual', [AdminStaffAttendanceController::class, 'manualRecord']);
        Route::get('export', [AdminStaffAttendanceController::class, 'exportCsv']);

        // Multi-campus staff assignments
        Route::get('assignments', [StaffCampusAssignmentController::class, 'index']);
        Route::post('assignments', [StaffCampusAssignmentController::class, 'store']);
        Route::put('assignments/{assignment}', [StaffCampusAssignmentController::class, 'update']);
        Route::delete('assignments/{assignment}', [StaffCampusAssignmentController::class, 'destroy']);
    });
});
