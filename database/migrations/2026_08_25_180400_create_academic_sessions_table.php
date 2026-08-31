<?php

use App\Enums\SessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedTinyInteger('term_count')->default(3);
            $table->string('status', 20)->default(SessionStatus::Planned->value);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('starts_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_sessions');
    }
};
