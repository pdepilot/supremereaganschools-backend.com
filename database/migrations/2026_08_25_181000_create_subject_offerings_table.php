<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_section_offering_id')->constrained('class_section_offerings')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['class_section_offering_id', 'subject_id'], 'subject_offerings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_offerings');
    }
};
