<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained('school_classes')->restrictOnDelete();
            $table->string('arm', 5)->default('');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_class_id', 'arm']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sections');
    }
};
