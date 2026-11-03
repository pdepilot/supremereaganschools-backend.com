<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('staff_attendance_adjustments');

        Schema::create('staff_attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_attendance_record_id')->constrained('staff_attendance_records')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('original_values');
            $table->json('requested_values');
            $table->text('reason');
            $table->string('status', 30)->default('pending');
            $table->text('review_notes')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['staff_attendance_record_id', 'status'], 'sa_adj_record_status_idx');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendance_adjustments');
    }
};
