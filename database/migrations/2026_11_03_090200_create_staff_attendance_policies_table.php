<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete();
            $table->string('role_slug', 50)->nullable();
            $table->string('name', 100);
            $table->time('expected_clock_in_time')->default('07:45:00');
            $table->time('expected_clock_out_time')->default('16:00:00');
            $table->unsignedInteger('grace_period_minutes')->default(15);
            $table->time('operating_hours_start')->default('06:30:00');
            $table->time('operating_hours_end')->default('18:30:00');
            $table->json('working_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['campus_id', 'role_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendance_policies');
    }
};

