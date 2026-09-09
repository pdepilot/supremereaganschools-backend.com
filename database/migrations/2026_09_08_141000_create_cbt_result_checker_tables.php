<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbt_result_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 8)->default('NGN');
            $table->unsignedInteger('checks_allowed')->default(1);
            $table->unsignedInteger('duration_days')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cbt_result_checker_purchases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('checker_code')->unique();
            $table->string('verification_code')->unique();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cbt_result_id')->constrained('cbt_results')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('cbt_result_products')->restrictOnDelete();
            $table->foreignId('online_payment_id')->nullable()->constrained('online_payments')->nullOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 8)->default('NGN');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('checks_allowed')->default(1);
            $table->unsignedInteger('checks_remaining')->default(0);
            $table->unsignedInteger('checked_count')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['student_profile_id', 'cbt_result_id']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('school_settings', function (Blueprint $table) {
            $table->boolean('cbt_result_details_require_payment')
                ->default(true)
                ->after('cbt_operator_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->dropColumn('cbt_result_details_require_payment');
        });

        Schema::dropIfExists('cbt_result_checker_purchases');
        Schema::dropIfExists('cbt_result_products');
    }
};
