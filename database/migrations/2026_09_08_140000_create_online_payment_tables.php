<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference')->unique();
            $table->string('provider', 40)->default('paystack');
            $table->string('provider_reference')->nullable()->index();
            $table->string('purpose', 60);
            $table->nullableMorphs('payable');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('student_profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();
            $table->string('email');
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 8)->default('NGN');
            $table->string('status', 20)->default('pending')->index();
            $table->string('authorization_url')->nullable();
            $table->string('access_code')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'status']);
        });

        Schema::create('paystack_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event')->nullable();
            $table->string('reference')->nullable()->index();
            $table->string('status', 20)->default('processed');
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paystack_webhook_events');
        Schema::dropIfExists('online_payments');
    }
};
