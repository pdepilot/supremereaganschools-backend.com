<?php

use App\Enums\StudentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('admission_number')->unique();
            $table->string('surname');
            $table->string('first_name');
            $table->string('other_names')->nullable();
            $table->string('gender', 10);
            $table->date('date_of_birth')->nullable();
            $table->string('nationality')->nullable();
            $table->string('state_of_origin')->nullable();
            $table->string('lga')->nullable();
            $table->text('home_address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->string('genotype', 5)->nullable();
            $table->text('medical_notes')->nullable();
            $table->string('interests')->nullable();
            $table->string('previous_school')->nullable();
            $table->string('status', 20)->default(StudentStatus::Active->value);
            $table->date('admitted_on')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['surname', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
