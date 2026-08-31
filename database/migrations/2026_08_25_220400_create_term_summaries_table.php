<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();
            $table->decimal('average', 6, 2)->nullable();
            $table->unsignedInteger('class_position')->nullable();
            $table->unsignedInteger('class_size')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'term_id'], 'term_summaries_unique');
            $table->index('term_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_summaries');
    }
};
