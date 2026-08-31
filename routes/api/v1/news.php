<?php

use App\Http\Controllers\Api\V1\News\PostCategoryController;
use App\Http\Controllers\Api\V1\News\PostController;
use App\Http\Controllers\Api\V1\News\PostTagController;
use App\Http\Controllers\Api\V1\News\PublicNewsController;
use App\Http\Controllers\Api\V1\News\PublishingSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:40,1'])->prefix('news')->name('news.public.')->group(function () {
    Route::get('/', [PublicNewsController::class, 'index'])->name('index');
    Route::get('/categories', [PublicNewsController::class, 'categories'])->name('categories');
    Route::get('/tags', [PublicNewsController::class, 'tags'])->name('tags');
    Route::get('/{slug}', [PublicNewsController::class, 'show'])->name('show');
});

Route::middleware(['web', 'auth', 'role:super_admin,school_admin'])->group(function () {
    Route::get('posts', [PostController::class, 'index'])->name('news.posts.index');
    Route::post('posts', [PostController::class, 'store'])->name('news.posts.store');
    Route::get('posts/{post}', [PostController::class, 'show'])->name('news.posts.show');
    Route::match(['put', 'post'], 'posts/{post}', [PostController::class, 'update'])->name('news.posts.update');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('news.posts.destroy');

    Route::get('post-categories', [PostCategoryController::class, 'index'])->name('news.categories.index');
    Route::post('post-categories', [PostCategoryController::class, 'store'])->name('news.categories.store');
    Route::put('post-categories/{post_category}', [PostCategoryController::class, 'update'])->name('news.categories.update');
    Route::delete('post-categories/{post_category}', [PostCategoryController::class, 'destroy'])->name('news.categories.destroy');

    Route::get('post-tags', [PostTagController::class, 'index'])->name('news.tags.index');
    Route::post('post-tags', [PostTagController::class, 'store'])->name('news.tags.store');
    Route::put('post-tags/{post_tag}', [PostTagController::class, 'update'])->name('news.tags.update');
    Route::delete('post-tags/{post_tag}', [PostTagController::class, 'destroy'])->name('news.tags.destroy');

    Route::get('publishing-settings', [PublishingSettingController::class, 'show'])->name('news.settings.show');
    Route::put('publishing-settings', [PublishingSettingController::class, 'update'])->name('news.settings.update');
});
