<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('assessment_type_id')->constrained('assessment_types')->restrictOnDelete();
            $table->decimal('score', 6, 2);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['enrollment_id', 'term_id', 'subject_id', 'assessment_type_id'],
                'assessment_scores_unique',
            );
            $table->index(['term_id', 'subject_id'], 'assessment_scores_term_subject_index');
            $table->index('entered_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_scores');
    }
};
