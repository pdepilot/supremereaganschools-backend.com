<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_section_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_section_id')->constrained('class_sections')->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->foreignId('campus_id')->constrained('campuses')->restrictOnDelete();
            $table->unsignedInteger('capacity')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['class_section_id', 'academic_session_id'], 'offerings_section_session_unique');
            $table->index('academic_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_section_offerings');
    }
};
