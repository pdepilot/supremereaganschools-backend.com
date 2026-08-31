<?php

use App\Enums\AttendanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('class_section_offering_id')->constrained('class_section_offerings')->restrictOnDelete();
            $table->date('marked_on');
            $table->string('status', 20)->default(AttendanceStatus::Present->value);
            $table->string('remark')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['enrollment_id', 'marked_on'], 'attendance_enrollment_date_unique');
            $table->index(['class_section_offering_id', 'marked_on'], 'attendance_offering_date_index');
            $table->index('marked_on');
            $table->index('status');
            $table->index('marked_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
