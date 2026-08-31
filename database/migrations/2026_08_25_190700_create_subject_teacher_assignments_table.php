<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->foreignId('subject_offering_id')->constrained('subject_offerings')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->date('assigned_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['staff_profile_id', 'subject_offering_id'], 'subject_teacher_staff_offering_unique');
            $table->index('subject_offering_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_teacher_assignments');
    }
};
