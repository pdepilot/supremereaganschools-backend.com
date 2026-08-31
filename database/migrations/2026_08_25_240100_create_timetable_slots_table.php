<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_section_offering_id')->constrained('class_section_offerings')->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->foreignId('staff_profile_id')->nullable()->constrained('staff_profiles')->nullOnDelete();
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['class_section_offering_id', 'day_of_week', 'starts_at'], 'timetable_slots_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_slots');
    }
};
