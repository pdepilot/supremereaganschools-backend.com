<?php

use App\Http\Controllers\Api\V1\Cbt\CbtAdminController;
use App\Http\Controllers\Api\V1\Cbt\CbtAdminExamController;
use App\Http\Controllers\Api\V1\Cbt\CbtAdminOpsController;
use App\Http\Controllers\Api\V1\Cbt\CbtAdminQuestionController;
use App\Http\Controllers\Api\V1\Cbt\CbtAdminResultCheckerController;
use App\Http\Controllers\Api\V1\Cbt\CbtAttemptController;
use App\Http\Controllers\Api\V1\Cbt\CbtResultCheckerController;
use App\Http\Controllers\Api\V1\Cbt\CbtStudentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'cbt.desk'])->prefix('cbt')->name('cbt.')->group(function () {
    Route::get('exams', [CbtStudentController::class, 'exams'])->name('exams.index');
    Route::get('exams/{exam}', [CbtStudentController::class, 'showExam'])->name('exams.show');
    Route::post('exams/{exam}/attempts', [CbtAttemptController::class, 'start'])->name('attempts.start');

    Route::get('attempts/{attempt}', [CbtAttemptController::class, 'show'])->name('attempts.show');
    Route::get('attempts/{attempt}/offline-package', [CbtAttemptController::class, 'offlinePackage'])
        ->middleware('throttle:30,1')
        ->name('attempts.offline-package');
    Route::post('attempts/{attempt}/sync', [CbtAttemptController::class, 'sync'])
        ->middleware('throttle:60,1')
        ->name('attempts.sync');
    Route::post('attempts/{attempt}/answers', [CbtAttemptController::class, 'saveAnswer'])->name('attempts.answers');
    Route::post('attempts/{attempt}/submit', [CbtAttemptController::class, 'submit'])->name('attempts.submit');
    Route::post('attempts/{attempt}/auto-submit', [CbtAttemptController::class, 'autoSubmit'])
        ->middleware('throttle:30,1')
        ->name('attempts.auto-submit');

    Route::get('results', [CbtStudentController::class, 'results'])->name('results.index');
    Route::get('results/{result}', [CbtResultCheckerController::class, 'showResult'])->name('results.show');
    Route::get('results/{result}/detailed', [CbtResultCheckerController::class, 'detailedResult'])->name('results.detailed');
    Route::get('results/{result}/access', [CbtResultCheckerController::class, 'access'])->name('results.access');
    Route::post('results/{result}/unlock', [CbtResultCheckerController::class, 'unlock'])
        ->middleware('throttle:10,1')
        ->name('results.unlock');
    Route::get('result-checkers/pricing', [CbtResultCheckerController::class, 'pricing'])->name('result-checkers.pricing');

    // Legacy purchase alias → unlock by result id in body (kept for older frontends during cutover).
    Route::post('result-checkers/purchase', function (\Illuminate\Http\Request $request, CbtResultCheckerController $controller) {
        $resultId = (int) $request->input('cbt_result_id');
        $result = \App\Models\CbtResult::query()->findOrFail($resultId);

        return $controller->unlock($request, $result);
    })->middleware('throttle:10,1')->name('result-checkers.purchase');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [CbtAdminController::class, 'entry'])->name('entry');
        Route::get('lookups', [CbtAdminController::class, 'lookups'])->name('lookups');
        Route::post('academic-sessions/ensure', [CbtAdminController::class, 'ensureAcademicSession'])->name('academic-sessions.ensure');
        Route::post('subjects', [CbtAdminController::class, 'storeSubject'])->name('subjects.store');
        Route::get('students', [CbtAdminController::class, 'students'])->name('students');
        Route::get('results', [CbtAdminController::class, 'results'])->name('results');

        Route::get('monitor', [CbtAdminOpsController::class, 'monitor'])->name('monitor');
        Route::get('attempts', [CbtAdminOpsController::class, 'attempts'])->name('attempts.index');
        Route::get('attempts/monitor', [CbtAdminOpsController::class, 'monitor'])->name('attempts.monitor');
        Route::post('attempts/{attempt}/extend', [CbtAdminOpsController::class, 'extendAttempt'])
            ->middleware('throttle:30,1')
            ->name('attempts.extend');
        Route::post('exams/{exam}/extend-timers', [CbtAdminOpsController::class, 'extendExamTimers'])
            ->middleware('throttle:20,1')
            ->name('exams.extend-timers');
        Route::post('exams/{exam}/schedule', [CbtAdminOpsController::class, 'updateExamSchedule'])
            ->middleware('throttle:20,1')
            ->name('exams.schedule');
        Route::get('reports/student-history', [CbtAdminOpsController::class, 'studentHistory'])->name('reports.student-history');
        Route::get('reports/student-history/export', [CbtAdminOpsController::class, 'exportStudentHistory'])->name('reports.student-history.export');
        Route::get('exams/{exam}/report', [CbtAdminOpsController::class, 'examReport'])->name('exams.report');
        Route::get('exams/{exam}/export/results', [CbtAdminOpsController::class, 'exportExamResults'])->name('exams.export.results');
        Route::get('exams/{exam}/export/attendance', [CbtAdminOpsController::class, 'exportExamAttendance'])->name('exams.export.attendance');

        Route::get('result-checkers/products', [CbtAdminResultCheckerController::class, 'products'])->name('result-checkers.products.index');
        Route::post('result-checkers/products', [CbtAdminResultCheckerController::class, 'storeProduct'])->name('result-checkers.products.store');
        Route::match(['put', 'patch', 'post'], 'result-checkers/products/{product}', [CbtAdminResultCheckerController::class, 'updateProduct'])->name('result-checkers.products.update');
        Route::post('result-checkers/policy', [CbtAdminResultCheckerController::class, 'updatePolicy'])->name('result-checkers.policy');
        Route::get('result-checkers/purchases', [CbtAdminResultCheckerController::class, 'purchases'])->name('result-checkers.purchases');
        Route::get('result-checkers/statistics', [CbtAdminResultCheckerController::class, 'statistics'])->name('result-checkers.statistics');

        Route::get('questions', [CbtAdminQuestionController::class, 'index'])->name('questions.index');
        Route::post('questions', [CbtAdminQuestionController::class, 'store'])->name('questions.store');
        Route::get('questions/{question}', [CbtAdminQuestionController::class, 'show'])->name('questions.show');
        Route::match(['put', 'post'], 'questions/{question}', [CbtAdminQuestionController::class, 'update'])->name('questions.update');
        Route::post('questions/{question}/active', [CbtAdminQuestionController::class, 'setActive'])->name('questions.active');

        Route::get('exams', [CbtAdminExamController::class, 'index'])->name('exams.index');
        Route::post('exams', [CbtAdminExamController::class, 'store'])->name('exams.store');
        Route::get('exams/{exam}', [CbtAdminExamController::class, 'show'])->name('exams.show');
        Route::match(['put', 'post'], 'exams/{exam}', [CbtAdminExamController::class, 'update'])->name('exams.update');
        Route::post('exams/{exam}/questions', [CbtAdminExamController::class, 'attachQuestion'])->name('exams.questions.attach');
        Route::delete('exams/{exam}/questions/{examQuestion}', [CbtAdminExamController::class, 'detachQuestion'])->name('exams.questions.detach');
        Route::post('exams/{exam}/questions/{examQuestion}/refresh', [CbtAdminExamController::class, 'refreshQuestion'])->name('exams.questions.refresh');
        Route::post('exams/{exam}/questions/{examQuestion}/marks', [CbtAdminExamController::class, 'updateQuestionMarks'])->name('exams.questions.marks');
        Route::post('exams/{exam}/reorder', [CbtAdminExamController::class, 'reorder'])->name('exams.reorder');
        Route::post('exams/{exam}/publish', [CbtAdminExamController::class, 'publish'])->name('exams.publish');
        Route::post('exams/{exam}/archive', [CbtAdminExamController::class, 'archive'])->name('exams.archive');
        Route::post('exams/{exam}/assignments', [CbtAdminExamController::class, 'assign'])->name('exams.assign');
        Route::delete('exams/{exam}/assignments/{assignment}', [CbtAdminExamController::class, 'unassign'])->name('exams.unassign');
        Route::get('exams/{exam}/preview', [CbtAdminExamController::class, 'preview'])->name('exams.preview');
    });
});
