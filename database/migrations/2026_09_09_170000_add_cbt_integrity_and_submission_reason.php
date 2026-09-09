<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_attempts', function (Blueprint $table) {
            $table->string('submission_reason', 40)->nullable()->after('submitted_at');
            $table->index('submission_reason', 'cbt_attempts_submission_reason_index');
        });

        Schema::create('cbt_exam_integrity_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->foreignId('attempt_id')->constrained('cbt_attempts')->cascadeOnDelete();
            $table->foreignId('student_profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();
            $table->string('event_type', 40);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('client_occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['attempt_id', 'created_at'], 'cbt_integrity_events_attempt_created_index');
            $table->index(['event_type', 'created_at'], 'cbt_integrity_events_type_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_exam_integrity_events');

        Schema::table('cbt_attempts', function (Blueprint $table) {
            $table->dropIndex('cbt_attempts_submission_reason_index');
            $table->dropColumn('submission_reason');
        });
    }
};
