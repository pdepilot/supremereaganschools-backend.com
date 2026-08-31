<?php

use App\Http\Controllers\Api\V1\AdmissionApplicationController;
use App\Http\Controllers\Api\V1\ContactEnquiryController;
use App\Http\Controllers\Api\V1\InboxController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:20,1'])->group(function () {
    Route::post('contact-enquiries', [ContactEnquiryController::class, 'store']);
    Route::post('admission-applications', [AdmissionApplicationController::class, 'store']);
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('inbox', [InboxController::class, 'index']);
    Route::post('inbox/open', [InboxController::class, 'open']);
    Route::post('inbox/clear-urgent', [InboxController::class, 'clearUrgent']);
    Route::get('documents/{document}/download', [InboxController::class, 'download']);

    Route::get('contact-enquiries', [ContactEnquiryController::class, 'index']);
    Route::get('contact-enquiries/{contact_enquiry}', [ContactEnquiryController::class, 'show']);
    Route::put('contact-enquiries/{contact_enquiry}', [ContactEnquiryController::class, 'update']);
    Route::post('contact-enquiries/{contact_enquiry}/reply', [ContactEnquiryController::class, 'reply']);
    Route::delete('contact-enquiries/{contact_enquiry}', [ContactEnquiryController::class, 'destroy']);

    Route::get('admission-applications', [AdmissionApplicationController::class, 'index']);
    Route::get('admission-applications/{admission_application}', [AdmissionApplicationController::class, 'show']);
    Route::put('admission-applications/{admission_application}', [AdmissionApplicationController::class, 'update']);
});
