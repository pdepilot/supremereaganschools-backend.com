<?php

use App\Services\News\PostService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(PostService::class)->releaseScheduled())
    ->everyFiveMinutes()
    ->name('news-release-scheduled');
