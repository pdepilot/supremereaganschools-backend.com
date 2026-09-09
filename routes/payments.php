<?php

use App\Http\Controllers\Payments\PaymentTestController;
use App\Http\Controllers\Payments\PaystackCallbackController;
use App\Http\Controllers\Payments\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/payments/paystack/callback', PaystackCallbackController::class)
    ->name('payments.paystack.callback');

Route::post('/payments/paystack/webhook', PaystackWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('payments.paystack.webhook');

Route::middleware(['auth'])->group(function () {
    Route::get('/payments/test', [PaymentTestController::class, 'page'])
        ->name('payments.test');
    Route::get('/payments/test/status', [PaymentTestController::class, 'statusPage'])
        ->name('payments.test.status');
});
