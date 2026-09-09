<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbt_result_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cbt_result_id')->constrained('cbt_results')->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->foreignId('online_payment_id')->constrained('online_payments')->restrictOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('online_payment_id');
            $table->unique(['cbt_result_id', 'student_profile_id']);
            $table->index(['student_profile_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_result_access');
    }
};
