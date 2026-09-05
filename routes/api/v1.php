<?php

use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/health', function () {
        return ApiResponse::success('API is available.', [
            'version' => 'v1',
            'name' => config('app.name'),
        ]);
    })->name('health');
});

require __DIR__.'/v1/academic.php';
require __DIR__.'/v1/people.php';
require __DIR__.'/v1/rbac.php';
require __DIR__.'/v1/admins.php';
require __DIR__.'/v1/attendance.php';
require __DIR__.'/v1/fees.php';
require __DIR__.'/v1/assessments.php';
require __DIR__.'/v1/admissions.php';
require __DIR__.'/v1/classroom.php';
require __DIR__.'/v1/mail.php';
require __DIR__.'/v1/news.php';

