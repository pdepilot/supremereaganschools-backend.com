<?php

use App\Http\Controllers\Api\V1\Cbt\CbtAdminController;
use App\Http\Controllers\Api\V1\Cbt\CbtAdminExamController;
use App\Http\Controllers\Api\V1\Cbt\CbtAdminQuestionController;
use App\Http\Controllers\Api\V1\Cbt\CbtAttemptController;
use App\Http\Controllers\Api\V1\Cbt\CbtStudentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('cbt')->name('cbt.')->group(function () {
    Route::get('exams', [CbtStudentController::class, 'exams'])->name('exams.index');
    Route::get('exams/{exam}', [CbtStudentController::class, 'showExam'])->name('exams.show');
    Route::post('exams/{exam}/attempts', [CbtAttemptController::class, 'start'])->name('attempts.start');

    Route::get('attempts/{attempt}', [CbtAttemptController::class, 'show'])->name('attempts.show');
    Route::post('attempts/{attempt}/answers', [CbtAttemptController::class, 'saveAnswer'])->name('attempts.answers');
    Route::post('attempts/{attempt}/submit', [CbtAttemptController::class, 'submit'])->name('attempts.submit');

    Route::get('results', [CbtStudentController::class, 'results'])->name('results.index');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [CbtAdminController::class, 'entry'])->name('entry');
        Route::get('lookups', [CbtAdminController::class, 'lookups'])->name('lookups');
        Route::get('students', [CbtAdminController::class, 'students'])->name('students');
        Route::get('results', [CbtAdminController::class, 'results'])->name('results');

        Route::get('questions', [CbtAdminQuestionController::class, 'index'])->name('questions.index');
        Route::post('questions', [CbtAdminQuestionController::class, 'store'])->name('questions.store');
        Route::get('questions/{question}', [CbtAdminQuestionController::class, 'show'])->name('questions.show');
        Route::put('questions/{question}', [CbtAdminQuestionController::class, 'update'])->name('questions.update');
        Route::post('questions/{question}/active', [CbtAdminQuestionController::class, 'setActive'])->name('questions.active');

        Route::get('exams', [CbtAdminExamController::class, 'index'])->name('exams.index');
        Route::post('exams', [CbtAdminExamController::class, 'store'])->name('exams.store');
        Route::get('exams/{exam}', [CbtAdminExamController::class, 'show'])->name('exams.show');
        Route::put('exams/{exam}', [CbtAdminExamController::class, 'update'])->name('exams.update');
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
