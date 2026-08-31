<?php

use App\Http\Controllers\FrontendController;
use App\Http\Controllers\News\AuthorPageController;
use App\Http\Controllers\News\CookieConsentController;
use App\Http\Controllers\News\DiscoveryController;
use App\Http\Controllers\News\LegalPageController;
use App\Http\Controllers\News\NewsImageController;
use App\Http\Controllers\News\NewsPageController;
use App\Http\Controllers\News\NewsletterController;
use App\Http\Controllers\News\ResourceDownloadController;
use App\Http\Controllers\News\ResourceHubController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::get('/', [FrontendController::class, 'home'])->name('home');

Route::get('/index.html', function () {
    return redirect()->route('home');
});

Route::get('/storage/news/{path}', [NewsImageController::class, 'show'])
    ->where('path', '.+')
    ->name('news.image');

Route::get('/news', [NewsPageController::class, 'index'])->name('news.index');
Route::get('/news/preview/{post}', [NewsPageController::class, 'preview'])
    ->middleware(['auth', 'role:super_admin,school_admin'])
    ->name('news.preview');
Route::get('/news/authors/{user}', [AuthorPageController::class, 'show'])
    ->whereNumber('user')
    ->name('news.author');
Route::post('/news/subscribe', [NewsletterController::class, 'store'])
    ->middleware('throttle:8,1')
    ->name('news.subscribe');
Route::get('/news/{category}/{slug}/download', [ResourceDownloadController::class, 'show'])
    ->where(['category' => '[A-Za-z0-9\-]+', 'slug' => '[A-Za-z0-9\-]+'])
    ->name('news.download');
Route::get('/news/{category}/{slug}', [NewsPageController::class, 'show'])
    ->where(['category' => '[A-Za-z0-9\-]+', 'slug' => '[A-Za-z0-9\-]+'])
    ->name('news.show');
Route::get('/news/{category}', [NewsPageController::class, 'category'])
    ->where('category', '[A-Za-z0-9\-]+')
    ->name('news.category');

Route::get('/resources', [ResourceHubController::class, 'index'])->name('resources.index');
Route::get('/resources/{hub}', [ResourceHubController::class, 'show'])
    ->where('hub', '[A-Za-z0-9\-]+')
    ->name('resources.show');

Route::get('/privacy', [LegalPageController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [LegalPageController::class, 'terms'])->name('legal.terms');
Route::post('/privacy/consent', [CookieConsentController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('legal.consent');

Route::get('/sitemap.xml', [DiscoveryController::class, 'sitemap'])->name('sitemap');
Route::get('/feed', [DiscoveryController::class, 'feed'])->name('feed');
Route::get('/rss', [DiscoveryController::class, 'feed'])->name('rss');
Route::get('/ads.txt', [DiscoveryController::class, 'adsTxt'])->name('ads-txt');
Route::get('/robots.txt', [DiscoveryController::class, 'robots'])->name('robots');

Route::get('/{page}', [FrontendController::class, 'publicPage'])
    ->whereIn('page', ['about', 'admissions', 'contact', 'nursery', 'primary', 'secondary', 'branches', 'pta', 'alumni'])
    ->name('site.page');

Route::get('/site/{path}', [FrontendController::class, 'legacy'])
    ->where('path', '.+\.html')
    ->name('site.legacy');
