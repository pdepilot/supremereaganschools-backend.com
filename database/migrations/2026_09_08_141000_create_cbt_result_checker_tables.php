<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: MySQL DDL auto-commits, so a prior failed run may have left
        // cbt_result_products in place without recording this migration.
        if (! Schema::hasTable('cbt_result_products')) {
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
        }

        if (! Schema::hasTable('cbt_result_checker_purchases')) {
            Schema::create('cbt_result_checker_purchases', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('checker_code')->unique();
                $table->string('verification_code')->unique();
                $table->foreignId('student_profile_id');
                $table->foreignId('user_id');
                $table->foreignId('cbt_result_id');
                $table->foreignId('product_id');
                $table->foreignId('online_payment_id')->nullable();
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

                // Explicit short names: auto names on this table exceed MySQL's 64-char limit.
                $table->foreign('student_profile_id', 'cbt_rc_purchases_student_fk')
                    ->references('id')->on('student_profiles')->restrictOnDelete();
                $table->foreign('user_id', 'cbt_rc_purchases_user_fk')
                    ->references('id')->on('users')->restrictOnDelete();
                $table->foreign('cbt_result_id', 'cbt_rc_purchases_result_fk')
                    ->references('id')->on('cbt_results')->restrictOnDelete();
                $table->foreign('product_id', 'cbt_rc_purchases_product_fk')
                    ->references('id')->on('cbt_result_products')->restrictOnDelete();
                $table->foreign('online_payment_id', 'cbt_rc_purchases_payment_fk')
                    ->references('id')->on('online_payments')->nullOnDelete();

                $table->index(
                    ['student_profile_id', 'cbt_result_id'],
                    'cbt_rc_purchases_student_result_idx',
                );
                $table->index(
                    ['status', 'expires_at'],
                    'cbt_rc_purchases_status_expires_idx',
                );
            });
        }

        if (! Schema::hasColumn('school_settings', 'cbt_result_details_require_payment')) {
            Schema::table('school_settings', function (Blueprint $table) {
                $table->boolean('cbt_result_details_require_payment')
                    ->default(true)
                    ->after('cbt_operator_user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('school_settings', 'cbt_result_details_require_payment')) {
            Schema::table('school_settings', function (Blueprint $table) {
                $table->dropColumn('cbt_result_details_require_payment');
            });
        }

        Schema::dropIfExists('cbt_result_checker_purchases');
        Schema::dropIfExists('cbt_result_products');
    }
};
