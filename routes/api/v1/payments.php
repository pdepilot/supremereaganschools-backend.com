<?php

use App\Http\Controllers\Payments\PaymentTestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('payments')->name('payments.')->group(function () {
    Route::get('test/bootstrap', [PaymentTestController::class, 'bootstrap'])
        ->middleware('throttle:30,1')
        ->name('test.bootstrap');

    Route::post('test/initialize', [PaymentTestController::class, 'initialize'])
        ->middleware('throttle:10,1')
        ->name('test.initialize');

    Route::get('transactions/{reference}', [PaymentTestController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('transactions.show');

    Route::post('transactions/{reference}/verify', [PaymentTestController::class, 'verify'])
        ->middleware('throttle:20,1')
        ->name('transactions.verify');

    Route::get('admin/transactions', [PaymentTestController::class, 'adminIndex'])
        ->middleware('throttle:60,1')
        ->name('admin.transactions');
});
