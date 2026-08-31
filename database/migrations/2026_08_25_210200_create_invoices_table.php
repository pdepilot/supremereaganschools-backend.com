<?php

use App\Enums\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();
            $table->string('status', 20)->default(InvoiceStatus::Unpaid->value);
            $table->unsignedBigInteger('total_kobo')->default(0);
            $table->unsignedBigInteger('paid_kobo')->default(0);
            $table->date('due_on')->nullable();
            $table->timestamps();

            $table->unique(['student_profile_id', 'term_id'], 'invoices_student_term_unique');
            $table->index('status');
            $table->index(['academic_session_id', 'term_id'], 'invoices_session_term_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
