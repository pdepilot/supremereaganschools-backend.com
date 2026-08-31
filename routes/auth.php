<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\FrontendController;
use Illuminate\Support\Facades\Route;

Route::get('/portal/login', [AuthenticatedSessionController::class, 'create'])
    ->middleware('guest.portal')
    ->name('login');

Route::get('/staff/login', [AuthenticatedSessionController::class, 'staffLogin'])
    ->middleware('guest.portal')
    ->name('staff.login');

Route::get('/parent/login', [AuthenticatedSessionController::class, 'parentLogin'])
    ->middleware('guest.portal')
    ->name('parent.login');

Route::get('/student/login', [AuthenticatedSessionController::class, 'studentLogin'])
    ->middleware('guest.portal')
    ->name('student.login');

Route::get('/admin/login', function () {
    return redirect()->route('login');
});

Route::get('/admin/office-login', function () {
    return redirect()->route('login');
});

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest.portal')
    ->name('login.store');

Route::get('/portal/forgot-password', [PasswordResetController::class, 'requestForm'])
    ->middleware('guest.portal')
    ->name('password.request');

Route::get('/staff/forgot-password', [PasswordResetController::class, 'requestForm'])
    ->middleware('guest.portal')
    ->name('staff.password.request');

Route::get('/parent/forgot-password', [PasswordResetController::class, 'requestForm'])
    ->middleware('guest.portal')
    ->name('parent.password.request');

Route::post('/forgot-password', [PasswordResetController::class, 'send'])
    ->middleware('guest.portal')
    ->name('password.email');

Route::get('/portal/reset-password/{token}', [PasswordResetController::class, 'resetForm'])
    ->middleware('guest.portal')
    ->name('password.reset');

Route::get('/staff/reset-password/{token}', [PasswordResetController::class, 'resetForm'])
    ->middleware('guest.portal')
    ->name('staff.password.reset');

Route::get('/parent/reset-password/{token}', [PasswordResetController::class, 'resetForm'])
    ->middleware('guest.portal')
    ->name('parent.password.reset');

Route::post('/reset-password', [PasswordResetController::class, 'update'])
    ->middleware('guest.portal')
    ->name('password.update');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/portal/home', [AuthenticatedSessionController::class, 'portalHome'])
    ->middleware(['auth', 'role:super_admin,school_admin'])
    ->name('portal.home');

Route::get('/admin/home', function () {
    return redirect()->route('portal.home');
});

Route::get('/staff/home', [AuthenticatedSessionController::class, 'staffHome'])
    ->middleware(['auth', 'role:teacher,staff,principal,vice_principal,accountant'])
    ->name('staff.home');

Route::get('/parent/home', [AuthenticatedSessionController::class, 'parentHome'])
    ->middleware(['auth', 'role:parent'])
    ->name('parent.home');

Route::get('/student/home', [AuthenticatedSessionController::class, 'studentHome'])
    ->middleware(['auth', 'role:student'])
    ->name('student.home');

Route::middleware(['auth', 'role:super_admin,school_admin'])->group(function () {
    Route::get('/portal', fn () => redirect('/portal/dashboard'));
    Route::get('/portal/{page}', [FrontendController::class, 'portalPage'])
        ->where('page', '[A-Za-z0-9_\-]+')
        ->name('portal.page');
});

Route::middleware(['auth', 'role:teacher,staff,principal,vice_principal,accountant'])->group(function () {
    Route::get('/staff', [FrontendController::class, 'staffPage'])->name('staff.desk');
    Route::get('/staff/{page}', [FrontendController::class, 'staffPage'])
        ->where('page', '[A-Za-z0-9_\-]+')
        ->name('staff.page');
});

Route::middleware(['auth', 'role:parent'])->group(function () {
    Route::get('/parent', [FrontendController::class, 'parentPage'])->name('parent.desk');
    Route::get('/parent/{page}', [FrontendController::class, 'parentPage'])
        ->where('page', '[A-Za-z0-9_\-]+')
        ->name('parent.page');
});

Route::middleware(['auth', 'role:student'])->group(function () {
    Route::get('/student', [FrontendController::class, 'studentPage'])->name('student.desk');
    Route::get('/student/{page}', [FrontendController::class, 'studentPage'])
        ->where('page', '[A-Za-z0-9_\-]+')
        ->name('student.page');
});
