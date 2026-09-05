<?php

use App\Enums\RoleSlug;
use App\Http\Controllers\Api\V1\ClassroomController;
use App\Http\Controllers\Api\V1\ParentDeskController;
use App\Http\Controllers\Api\V1\StaffDeskController;
use App\Http\Controllers\Api\V1\StaffReportController;
use App\Http\Controllers\Api\V1\StudentDeskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'role:student'])->group(function () {
    Route::get('student-desk', [StudentDeskController::class, 'show']);
});

Route::middleware(['web', 'auth', 'role:parent'])->group(function () {
    Route::get('parent-desk', [ParentDeskController::class, 'show']);
});

Route::middleware(['web', 'auth', 'role:'.RoleSlug::staffDeskMiddleware()])->group(function () {
    Route::get('staff-desk', [StaffDeskController::class, 'show']);
    Route::get('staff-reports', [StaffReportController::class, 'index']);
    Route::get('staff-reports/export', [StaffReportController::class, 'export']);
    Route::get('staff-reports/generate', [StaffReportController::class, 'show']);
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('classroom/context', [ClassroomController::class, 'context']);

    Route::get('announcements', [ClassroomController::class, 'announcements']);
    Route::post('announcements', [ClassroomController::class, 'storeAnnouncement']);
    Route::get('announcements/{announcement}', [ClassroomController::class, 'showAnnouncement']);
    Route::put('announcements/{announcement}', [ClassroomController::class, 'updateAnnouncement']);
    Route::delete('announcements/{announcement}', [ClassroomController::class, 'destroyAnnouncement']);

    Route::get('timetable', [ClassroomController::class, 'timetable']);
    Route::post('timetable', [ClassroomController::class, 'storeTimetable']);
    Route::put('timetable/{timetable_slot}', [ClassroomController::class, 'updateTimetable']);
    Route::delete('timetable/{timetable_slot}', [ClassroomController::class, 'destroyTimetable']);

    Route::get('assignments', [ClassroomController::class, 'assignments']);
    Route::post('assignments', [ClassroomController::class, 'storeAssignment']);
    Route::get('assignments/{assignment}', [ClassroomController::class, 'showAssignment']);
    Route::put('assignments/{assignment}', [ClassroomController::class, 'updateAssignment']);
    Route::delete('assignments/{assignment}', [ClassroomController::class, 'destroyAssignment']);
    Route::get('assignments/{assignment}/submissions', [ClassroomController::class, 'submissions']);
    Route::post('assignments/{assignment}/submissions', [ClassroomController::class, 'storeSubmission']);

    Route::get('learning-materials', [ClassroomController::class, 'materials']);
    Route::post('learning-materials', [ClassroomController::class, 'storeMaterial']);
    Route::delete('learning-materials/{learning_material}', [ClassroomController::class, 'destroyMaterial']);

    Route::get('messages/recipients', [ClassroomController::class, 'recipients']);
    Route::get('conversations', [ClassroomController::class, 'conversations']);
    Route::post('conversations', [ClassroomController::class, 'storeConversation']);
    Route::get('conversations/{conversation}', [ClassroomController::class, 'showConversation']);
    Route::post('conversations/{conversation}/messages', [ClassroomController::class, 'storeMessage']);

    Route::get('notifications', [ClassroomController::class, 'notifications']);
    Route::post('notifications/read-all', [ClassroomController::class, 'readAllNotifications']);
    Route::post('notifications/{notification}/read', [ClassroomController::class, 'readNotification']);
});
