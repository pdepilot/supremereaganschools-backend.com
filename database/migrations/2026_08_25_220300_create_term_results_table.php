<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->decimal('ca_total', 6, 2)->default(0);
            $table->decimal('exam_score', 6, 2)->default(0);
            $table->decimal('total', 6, 2)->default(0);
            $table->string('grade', 5)->nullable();
            $table->string('remark')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'term_id', 'subject_id'], 'term_results_unique');
            $table->index(['term_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_results');
    }
};
