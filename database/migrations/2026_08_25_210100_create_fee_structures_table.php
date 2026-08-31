<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_type_id')->constrained('fee_types')->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained('terms')->restrictOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('levels')->restrictOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->timestamps();

            $table->index(['academic_session_id', 'term_id', 'fee_type_id'], 'fee_structures_session_term_type_index');
            $table->index('school_class_id');
            $table->index('level_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structures');
    }
};
