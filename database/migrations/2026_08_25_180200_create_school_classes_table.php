<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained('levels')->restrictOnDelete();
            $table->string('name');
            $table->string('short_code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['level_id', 'name']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_classes');
    }
};
