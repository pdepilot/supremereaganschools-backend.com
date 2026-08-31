<?php

use App\Enums\SessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('term_number');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default(SessionStatus::Planned->value);
            $table->timestamps();

            $table->unique(['academic_session_id', 'term_number']);
            $table->unique(['academic_session_id', 'name']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms');
    }
};
