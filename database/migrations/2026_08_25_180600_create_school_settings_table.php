<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('motto')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('admissions_email')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('website')->nullable();
            $table->string('timezone')->default('Africa/Lagos');
            $table->date('founded_on')->nullable();
            $table->time('office_opens_at')->nullable();
            $table->time('office_closes_at')->nullable();
            $table->string('logo_path')->nullable();
            $table->foreignId('current_academic_session_id')->nullable()->constrained('academic_sessions')->restrictOnDelete();
            $table->foreignId('current_term_id')->nullable()->constrained('terms')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_settings');
    }
};
