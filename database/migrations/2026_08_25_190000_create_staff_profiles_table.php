<?php

use App\Enums\StaffStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('staff_number')->unique();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('gender', 10)->nullable();
            $table->string('job_title')->nullable();
            $table->string('phone')->nullable();
            $table->date('employed_on')->nullable();
            $table->string('status', 20)->default(StaffStatus::Active->value);
            $table->string('photo_path')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('department_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
