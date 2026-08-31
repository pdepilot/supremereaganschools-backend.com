<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_section_offering_id')->constrained('class_section_offerings')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->date('due_on');
            $table->timestamps();
            $table->softDeletes();

            $table->index('due_on');
            $table->index('class_section_offering_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
