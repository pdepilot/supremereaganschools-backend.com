<?php

use App\Http\Controllers\Cbt\CbtAuthController;
use App\Http\Controllers\Cbt\CbtWebController;
use Illuminate\Support\Facades\Route;

Route::get('/cbt/login', [CbtAuthController::class, 'create'])
    ->middleware('guest.portal')
    ->name('cbt.login');

Route::post('/cbt/login', [CbtAuthController::class, 'store'])
    ->middleware('guest.portal')
    ->name('cbt.login.store');

Route::post('/cbt/logout', [CbtAuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('cbt.logout');

Route::middleware(['auth', 'role:cbt', 'cbt.desk'])->prefix('cbt')->group(function () {
    Route::get('/', [CbtAuthController::class, 'home'])->name('cbt.home');
    Route::get('/exams', [CbtWebController::class, 'exams'])->name('cbt.exams');
    Route::get('/exams/{exam}', [CbtWebController::class, 'exam'])->name('cbt.exams.show');
    Route::get('/attempts/{attempt}', [CbtWebController::class, 'attempt'])->name('cbt.attempts.show');
    Route::get('/results', [CbtWebController::class, 'results'])->name('cbt.results');

    Route::get('/admin', [CbtAuthController::class, 'adminHome'])->name('cbt.admin.home');
    Route::get('/admin/questions', [CbtWebController::class, 'adminQuestions'])->name('cbt.admin.questions');
    Route::get('/admin/exams', [CbtWebController::class, 'adminExams'])->name('cbt.admin.exams');
    Route::get('/admin/exams/{exam}', [CbtWebController::class, 'adminExam'])->name('cbt.admin.exams.show');
    Route::get('/admin/exams/{exam}/preview', [CbtWebController::class, 'adminPreview'])->name('cbt.admin.exams.preview');
    Route::get('/admin/results', [CbtWebController::class, 'adminResults'])->name('cbt.admin.results');
    Route::get('/admin/{page}', [CbtWebController::class, 'adminPage'])
        ->where('page', '[A-Za-z0-9_\-]+')
        ->name('cbt.admin.page');
});
