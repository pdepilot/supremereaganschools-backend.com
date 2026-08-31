<?php

use App\Http\Controllers\Api\V1\AssessmentCatalogueController;
use App\Http\Controllers\Api\V1\GradeController;
use App\Http\Controllers\Api\V1\ResultController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('assessment-types', [AssessmentCatalogueController::class, 'types']);
    Route::get('grade-scales', [AssessmentCatalogueController::class, 'scales']);

    Route::get('grades/contexts', [GradeController::class, 'contexts']);
    Route::get('grades/register', [GradeController::class, 'register']);
    Route::post('grades/bulk', [GradeController::class, 'bulk']);
    Route::post('grades', [GradeController::class, 'store']);

    Route::get('results/summary', [ResultController::class, 'summary']);
    Route::put('results/comments', [ResultController::class, 'comments']);
    Route::get('results', [ResultController::class, 'index']);
});
