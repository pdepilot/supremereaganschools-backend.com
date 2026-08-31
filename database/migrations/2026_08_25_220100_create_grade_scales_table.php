<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_scales', function (Blueprint $table) {
            $table->id();
            $table->decimal('min_score', 6, 2);
            $table->decimal('max_score', 6, 2);
            $table->string('grade', 5);
            $table->string('remark');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('grade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_scales');
    }
};
