<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->string('session_name');
            $table->foreignId('level_id')->nullable()->constrained('levels')->nullOnDelete();
            $table->string('class_applied');
            $table->string('entry_term');
            $table->string('surname');
            $table->string('first_name');
            $table->string('other_names')->nullable();
            $table->string('gender', 10);
            $table->date('date_of_birth');
            $table->string('nationality');
            $table->string('state_of_origin');
            $table->string('lga')->nullable();
            $table->text('home_address');
            $table->string('previous_school')->nullable();
            $table->string('last_class')->nullable();
            $table->string('parent_name');
            $table->string('relationship', 20);
            $table->string('parent_phone');
            $table->string('parent_email');
            $table->string('parent_occupation')->nullable();
            $table->string('parent_second_phone')->nullable();
            $table->text('parent_address')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('genotype')->nullable();
            $table->text('allergies')->nullable();
            $table->string('interests')->nullable();
            $table->string('status', 30);
            $table->foreignId('student_profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('parent_email');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_applications');
    }
};
