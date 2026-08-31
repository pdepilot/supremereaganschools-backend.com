<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->foreignId('from_enrollment_id')->nullable()->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('to_enrollment_id')->nullable()->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->string('decision');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('student_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
