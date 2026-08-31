<?php

use App\Http\Controllers\Api\V1\FeeStructureController;
use App\Http\Controllers\Api\V1\FeeTypeController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('invoices/summary', [InvoiceController::class, 'summary']);
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::get('invoices/{invoice}/statement', [InvoiceController::class, 'statement']);
    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/{payment}', [PaymentController::class, 'show']);
    Route::get('me/fees/summary', [InvoiceController::class, 'mineSummary']);
    Route::get('me/fees', [InvoiceController::class, 'mine']);
    Route::get('me/payments', [PaymentController::class, 'mine']);
    Route::get('students/{student_profile}/fees/summary', [InvoiceController::class, 'studentSummary']);
    Route::get('students/{student_profile}/fees', [InvoiceController::class, 'forStudent']);

    Route::middleware('role:super_admin,school_admin')->group(function () {
        Route::get('fee-types', [FeeTypeController::class, 'index']);
        Route::post('fee-types', [FeeTypeController::class, 'store']);
        Route::get('fee-types/{fee_type}', [FeeTypeController::class, 'show']);
        Route::put('fee-types/{fee_type}', [FeeTypeController::class, 'update']);
        Route::delete('fee-types/{fee_type}', [FeeTypeController::class, 'destroy']);

        Route::get('fee-structures', [FeeStructureController::class, 'index']);
        Route::post('fee-structures', [FeeStructureController::class, 'store']);
        Route::get('fee-structures/{fee_structure}', [FeeStructureController::class, 'show']);
        Route::put('fee-structures/{fee_structure}', [FeeStructureController::class, 'update']);
        Route::delete('fee-structures/{fee_structure}', [FeeStructureController::class, 'destroy']);

        Route::post('invoices', [InvoiceController::class, 'store']);
        Route::post('invoices/generate', [InvoiceController::class, 'generate']);
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy']);

        Route::post('payments', [PaymentController::class, 'store']);
        Route::post('payments/{payment}/void', [PaymentController::class, 'void']);
    });
});
