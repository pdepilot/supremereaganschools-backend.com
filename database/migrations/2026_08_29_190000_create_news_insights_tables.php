<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('post_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('featured_image')->nullable();
            $table->string('featured_image_alt')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('visibility', 20)->default('public');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('post_categories')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('og_title')->nullable();
            $table->string('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->unsignedSmallInteger('reading_time')->nullable();
            $table->boolean('allow_comments')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->boolean('ads_enabled')->default(true);
            $table->boolean('indexable')->default(true);
            $table->timestamps();

            $table->index('status');
            $table->index('published_at');
            $table->index('category_id');
            $table->index('author_id');
            $table->index('is_featured');
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('post_tag_id')->constrained('post_tags')->cascadeOnDelete();
            $table->unique(['post_id', 'post_tag_id']);
        });

        Schema::create('publishing_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('adsense_enabled')->default(false);
            $table->string('adsense_client_id')->nullable();
            $table->boolean('adsense_auto_ads')->default(false);
            $table->text('adsense_verification')->nullable();
            $table->boolean('analytics_enabled')->default(false);
            $table->string('analytics_measurement_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('post_tags');
        Schema::dropIfExists('post_categories');
        Schema::dropIfExists('publishing_settings');
    }
};
