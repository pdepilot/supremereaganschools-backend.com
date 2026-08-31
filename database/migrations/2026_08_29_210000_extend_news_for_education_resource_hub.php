<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('content_type', 40)->default('article')->after('visibility');
            $table->string('cta_type', 40)->nullable()->after('indexable');
            $table->string('cta_strength', 20)->default('standard')->after('cta_type');
            $table->string('pillar_topic')->nullable()->after('cta_strength');
            $table->string('supporting_topic')->nullable()->after('pillar_topic');
            $table->string('audience', 40)->nullable()->after('supporting_topic');
            $table->string('educational_level', 40)->nullable()->after('audience');
            $table->string('intent', 40)->nullable()->after('educational_level');
            $table->timestamp('last_reviewed_at')->nullable()->after('intent');
            $table->timestamp('review_due_at')->nullable()->after('last_reviewed_at');
            $table->string('resource_path')->nullable()->after('review_due_at');
            $table->string('resource_original_name')->nullable()->after('resource_path');
            $table->boolean('is_parent_resource')->default(false)->after('resource_original_name');
            $table->boolean('child_directed')->default(false)->after('is_parent_resource');

            $table->index('content_type');
            $table->index('review_due_at');
            $table->index('is_parent_resource');
        });

        Schema::create('resource_hubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('kicker')->nullable();
            $table->text('intro');
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('cta_type', 40)->default('about');
            $table->text('cta_copy')->nullable();
            $table->boolean('is_parent_hub')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('resource_hub_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_hub_id')->constrained('resource_hubs')->cascadeOnDelete();
            $table->foreignId('post_category_id')->constrained('post_categories')->cascadeOnDelete();
            $table->unique(['resource_hub_id', 'post_category_id']);
        });

        Schema::create('author_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('public_role')->nullable();
            $table->text('biography')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });

        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('consented_at');
            $table->string('status', 20)->default('active');
            $table->string('source')->nullable();
            $table->timestamps();
        });

        Schema::table('contact_enquiries', function (Blueprint $table) {
            $table->string('intended_level')->nullable()->after('subject');
            $table->string('enquiry_type')->nullable()->after('intended_level');
            $table->string('source_url')->nullable()->after('enquiry_type');
            $table->foreignId('source_post_id')->nullable()->after('source_url')->constrained('posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contact_enquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_post_id');
            $table->dropColumn(['intended_level', 'enquiry_type', 'source_url']);
        });

        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('author_profiles');
        Schema::dropIfExists('resource_hub_category');
        Schema::dropIfExists('resource_hubs');

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['content_type']);
            $table->dropIndex(['review_due_at']);
            $table->dropIndex(['is_parent_resource']);
            $table->dropColumn([
                'content_type',
                'cta_type',
                'cta_strength',
                'pillar_topic',
                'supporting_topic',
                'audience',
                'educational_level',
                'intent',
                'last_reviewed_at',
                'review_due_at',
                'resource_path',
                'resource_original_name',
                'is_parent_resource',
                'child_directed',
            ]);
        });
    }
};
