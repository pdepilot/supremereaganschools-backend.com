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

Route::get('/result/verify', function () {
    return app(\App\Support\FrontendPage::class)->response('public/result-verify.html', area: 'public');
})->name('result.verify');

Route::post('/api/v1/result/verify', [\App\Http\Controllers\Cbt\PublicResultVerificationController::class, 'verify'])
    ->middleware(['web', 'throttle:20,1'])
    ->name('api.v1.result.verify');

Route::middleware(['auth', 'role:cbt', 'cbt.desk'])->prefix('cbt')->group(function () {
    Route::get('/', [CbtAuthController::class, 'home'])->name('cbt.home');
    Route::get('/exams', [CbtWebController::class, 'exams'])->name('cbt.exams');
    Route::get('/exams/{exam}', [CbtWebController::class, 'exam'])->name('cbt.exams.show');
    Route::get('/attempts/{attempt}', [CbtWebController::class, 'attempt'])->name('cbt.attempts.show');
    Route::get('/results', [CbtWebController::class, 'results'])->name('cbt.results');
    Route::get('/results/{result}', [CbtWebController::class, 'resultShow'])->name('cbt.results.show');
    Route::get('/result-checker/success', [CbtWebController::class, 'resultCheckerSuccess'])->name('cbt.result-checker.success');

    Route::get('/admin', [CbtAuthController::class, 'adminHome'])->name('cbt.admin.home');
    Route::get('/admin/questions', [CbtWebController::class, 'adminQuestions'])->name('cbt.admin.questions');
    Route::get('/admin/exams', [CbtWebController::class, 'adminExams'])->name('cbt.admin.exams');
    Route::get('/admin/exams/{exam}', [CbtWebController::class, 'adminExam'])->name('cbt.admin.exams.show');
    Route::get('/admin/exams/{exam}/preview', [CbtWebController::class, 'adminPreview'])->name('cbt.admin.exams.preview');
    Route::get('/admin/results', [CbtWebController::class, 'adminResults'])->name('cbt.admin.results');
    Route::get('/admin/attempts', [CbtWebController::class, 'adminAttempts'])->name('cbt.admin.attempts');
    Route::get('/admin/monitor', [CbtWebController::class, 'adminMonitor'])->name('cbt.admin.monitor');
    Route::get('/admin/reports', [CbtWebController::class, 'adminReports'])->name('cbt.admin.reports');
    Route::get('/admin/result-checkers', [CbtWebController::class, 'adminResultCheckers'])->name('cbt.admin.result-checkers');
    Route::get('/admin/{page}', [CbtWebController::class, 'adminPage'])
        ->where('page', '[A-Za-z0-9_\-]+')
        ->name('cbt.admin.page');
});
