<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('staff_attendance_verification_logs');

        Schema::create('staff_attendance_verification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->cascadeOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete();
            $table->string('action', 30);
            $table->dateTime('attempted_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy', 8, 2)->nullable();
            $table->decimal('distance_meters', 8, 2)->nullable();
            $table->boolean('is_within_boundary')->default(false);
            $table->boolean('accuracy_acceptable')->default(false);
            $table->string('result', 30);
            $table->string('rejection_reason')->nullable();
            $table->boolean('is_suspicious')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['staff_profile_id', 'attempted_at'], 'savl_staff_attempt_idx');
            $table->index(['campus_id', 'attempted_at'], 'savl_campus_attempt_idx');
            $table->index('result');
            $table->index('is_suspicious');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendance_verification_logs');
    }
};
