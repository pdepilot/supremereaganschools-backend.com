<?php

use App\Enums\GuardianRelationship;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_profile_id')->constrained('guardian_profiles')->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->string('relationship', 20)->default(GuardianRelationship::Guardian->value);
            $table->boolean('is_primary')->default(false);
            $table->boolean('can_login')->default(true);
            $table->timestamps();

            $table->unique(['guardian_profile_id', 'student_profile_id'], 'guardian_student_unique');
            $table->index('student_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_student');
    }
};
