<?php

use App\Http\Controllers\Api\V1\EmailCenterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('email-center', [EmailCenterController::class, 'desk']);
    Route::get('email-center/templates', [EmailCenterController::class, 'templates']);
    Route::get('email-center/people', [EmailCenterController::class, 'people']);
    Route::get('email-center/outbox', [EmailCenterController::class, 'outbox']);
    Route::post('email-center/preview', [EmailCenterController::class, 'preview']);
    Route::post('email-center/send', [EmailCenterController::class, 'send']);
});
