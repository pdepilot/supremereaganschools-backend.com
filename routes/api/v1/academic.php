<?php

use App\Http\Controllers\Api\V1\AcademicSessionController;
use App\Http\Controllers\Api\V1\CampusController;
use App\Http\Controllers\Api\V1\ClassSectionController;
use App\Http\Controllers\Api\V1\ClassSectionOfferingController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\LevelController;
use App\Http\Controllers\Api\V1\LevelDeskController;
use App\Http\Controllers\Api\V1\PortalDashboardController;
use App\Http\Controllers\Api\V1\PortalReportController;
use App\Http\Controllers\Api\V1\SchoolClassController;
use App\Http\Controllers\Api\V1\SchoolSettingController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubjectOfferingController;
use App\Http\Controllers\Api\V1\TermController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'role:super_admin,school_admin'])->group(function () {
    Route::get('portal-dashboard', [PortalDashboardController::class, 'show']);
    Route::get('portal-reports', [PortalReportController::class, 'show']);
    Route::get('portal-reports/catalogue', [PortalReportController::class, 'catalogue']);
    Route::get('portal-reports/generate', [PortalReportController::class, 'generate']);
    Route::get('portal-reports/export', [PortalReportController::class, 'export']);
    Route::get('level-desks/{wing}', [LevelDeskController::class, 'show']);
    Route::get('school-settings', [SchoolSettingController::class, 'show']);
    Route::put('school-settings', [SchoolSettingController::class, 'update']);
    Route::get('desk-access', [SchoolSettingController::class, 'desks']);

    Route::apiResource('campuses', CampusController::class)->except(['show']);
    Route::get('departments', [DepartmentController::class, 'index']);
    Route::post('departments', [DepartmentController::class, 'store']);

    Route::post('academic-sessions/{academic_session}/activate', [AcademicSessionController::class, 'activate']);
    Route::post('academic-sessions/{academic_session}/promote', [AcademicSessionController::class, 'promote']);
    Route::apiResource('academic-sessions', AcademicSessionController::class);

    Route::get('academic-sessions/{academic_session}/terms', [TermController::class, 'index']);
    Route::post('academic-sessions/{academic_session}/terms', [TermController::class, 'store']);
    Route::post('terms/{term}/activate', [TermController::class, 'activate']);
    Route::put('terms/{term}', [TermController::class, 'update']);
    Route::delete('terms/{term}', [TermController::class, 'destroy']);

    Route::apiResource('levels', LevelController::class)->except(['show']);
    Route::apiResource('classes', SchoolClassController::class)->parameters(['classes' => 'school_class']);

    Route::get('classes/{school_class}/sections', [ClassSectionController::class, 'index']);
    Route::post('classes/{school_class}/sections', [ClassSectionController::class, 'store']);
    Route::put('sections/{class_section}', [ClassSectionController::class, 'update']);
    Route::delete('sections/{class_section}', [ClassSectionController::class, 'destroy']);

    Route::apiResource('subjects', SubjectController::class)->except(['show']);
    Route::apiResource('class-section-offerings', ClassSectionOfferingController::class)->except(['show']);
    Route::apiResource('subject-offerings', SubjectOfferingController::class)->except(['show', 'update']);
});
