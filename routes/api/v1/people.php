<?php

use App\Http\Controllers\Api\V1\ClassTeacherAssignmentController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\GuardianController;
use App\Http\Controllers\Api\V1\MeAccountController;
use App\Http\Controllers\Api\V1\MePeopleController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\SubjectTeacherAssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('me', [MeAccountController::class, 'show']);
    Route::put('me', [MeAccountController::class, 'update']);
    Route::put('me/password', [MeAccountController::class, 'updatePassword']);
    Route::get('me/children', [MePeopleController::class, 'children']);
    Route::get('me/enrollments', [MePeopleController::class, 'enrollments']);
    Route::get('me/students', [MePeopleController::class, 'students']);

    Route::get('students', [StudentController::class, 'index']);
    Route::get('students/{student_profile}/photo', [StudentController::class, 'photo']);
    Route::get('students/{student_profile}', [StudentController::class, 'show']);
    Route::get('enrollments', [EnrollmentController::class, 'index']);
    Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show']);
    Route::get('staff', [StaffController::class, 'index']);
    Route::get('staff/{staff_profile}', [StaffController::class, 'show']);
    Route::get('guardians/{guardian_profile}', [GuardianController::class, 'show']);
    Route::get('class-teacher-assignments', [ClassTeacherAssignmentController::class, 'index']);
    Route::get('subject-teacher-assignments', [SubjectTeacherAssignmentController::class, 'index']);

    Route::middleware('role:super_admin,school_admin')->group(function () {
        Route::post('staff', [StaffController::class, 'store']);
        Route::put('staff/{staff_profile}', [StaffController::class, 'update']);
        Route::post('staff/{staff_profile}/suspend', [StaffController::class, 'suspend']);
        Route::post('staff/{staff_profile}/reinstate', [StaffController::class, 'reinstate']);
        Route::delete('staff/{staff_profile}', [StaffController::class, 'destroy']);

        Route::post('students', [StudentController::class, 'store']);
        Route::put('students/{student_profile}', [StudentController::class, 'update']);
        Route::post('students/{student_profile}/suspend', [StudentController::class, 'suspend']);
        Route::post('students/{student_profile}/reinstate', [StudentController::class, 'reinstate']);
        Route::delete('students/{student_profile}', [StudentController::class, 'destroy']);

        Route::get('guardians', [GuardianController::class, 'index']);
        Route::post('guardians', [GuardianController::class, 'store']);
        Route::put('guardians/{guardian_profile}', [GuardianController::class, 'update']);
        Route::delete('guardians/{guardian_profile}', [GuardianController::class, 'destroy']);
        Route::post('guardians/{guardian_profile}/students', [GuardianController::class, 'link']);
        Route::delete('guardian-students/{guardian_student}', [GuardianController::class, 'unlink']);

        Route::post('enrollments', [EnrollmentController::class, 'store']);
        Route::put('enrollments/{enrollment}', [EnrollmentController::class, 'update']);
        Route::delete('enrollments/{enrollment}', [EnrollmentController::class, 'destroy']);

        Route::post('class-teacher-assignments', [ClassTeacherAssignmentController::class, 'store']);
        Route::delete('class-teacher-assignments/{class_teacher_assignment}', [ClassTeacherAssignmentController::class, 'destroy']);

        Route::post('subject-teacher-assignments', [SubjectTeacherAssignmentController::class, 'store']);
        Route::delete('subject-teacher-assignments/{subject_teacher_assignment}', [SubjectTeacherAssignmentController::class, 'destroy']);
    });
});
