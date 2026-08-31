<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('category', 20)->nullable();
            $table->string('audience', 30);
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('status', 20);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('published_at');
            $table->index('audience');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
