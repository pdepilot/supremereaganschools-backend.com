<?php

use App\Enums\CbtAttemptMode;
use App\Enums\CbtAttemptStatus;
use App\Enums\CbtExamStatus;
use App\Enums\CbtQuestionDifficulty;
use App\Enums\CbtQuestionType;
use App\Enums\CbtSyncDirection;
use App\Enums\CbtSyncLogStatus;
use App\Enums\CbtSyncStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive CBT schema only. Does not alter existing production tables.
 *
 * Publish integrity (enforced in application services in later phases):
 * - Question bank (`cbt_questions` / `cbt_question_options`) remains editable for future exams.
 * - `cbt_exam_questions` / `cbt_exam_question_options` hold a working/snapshot copy of stem,
 *   marks, type, explanation, and options (including is_correct for server marking).
 * - On publish, snapshot rows are frozen (`is_frozen` / `frozen_at`); bank edits must not
 *   mutate published exam configuration.
 * - Attempts and answers reference `cbt_exam_questions` (not the live bank) as authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->string('topic')->nullable();
            $table->string('difficulty', 20)->default(CbtQuestionDifficulty::Medium->value);
            $table->string('type', 20)->default(CbtQuestionType::Mcq->value);
            $table->text('stem');
            $table->decimal('marks', 8, 2)->default(1);
            $table->text('explanation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_class_id', 'subject_id'], 'cbt_questions_class_subject_index');
            $table->index(['subject_id', 'is_active'], 'cbt_questions_subject_active_index');
        });

        Schema::create('cbt_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->string('label', 8)->nullable();
            $table->text('body');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['question_id', 'sort_order'], 'cbt_question_options_order_unique');
        });

        Schema::create('cbt_exams', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('class_section_offering_id')->constrained('class_section_offerings')->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('question_count')->default(0);
            $table->decimal('pass_mark', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->default(0);
            $table->boolean('randomize_questions')->default(false);
            $table->boolean('randomize_options')->default(false);
            $table->unsignedInteger('max_attempts')->default(1);
            $table->string('status', 20)->default(CbtExamStatus::Draft->value);
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('write_to_assessment_score')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at'], 'cbt_exams_status_starts_index');
            $table->index(['class_section_offering_id', 'term_id'], 'cbt_exams_offering_term_index');
            $table->index(['subject_id', 'academic_session_id'], 'cbt_exams_subject_session_index');
        });

        Schema::create('cbt_exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('cbt_exams')->restrictOnDelete();
            $table->foreignId('question_id')->constrained('cbt_questions')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('marks', 8, 2);
            $table->string('type', 20)->default(CbtQuestionType::Mcq->value);
            $table->text('stem');
            $table->text('explanation')->nullable();
            $table->boolean('is_frozen')->default(false);
            $table->timestamp('frozen_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'question_id'], 'cbt_exam_questions_exam_question_unique');
            $table->unique(['exam_id', 'sort_order'], 'cbt_exam_questions_exam_order_unique');
            $table->index(['exam_id', 'is_frozen'], 'cbt_exam_questions_exam_frozen_index');
        });

        Schema::create('cbt_exam_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_question_id')->constrained('cbt_exam_questions')->restrictOnDelete();
            $table->foreignId('source_option_id')->nullable()->constrained('cbt_question_options')->nullOnDelete();
            $table->string('label', 8)->nullable();
            $table->text('body');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['exam_question_id', 'sort_order'], 'cbt_exam_q_options_order_unique');
        });

        Schema::create('cbt_exam_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('cbt_exams')->restrictOnDelete();
            $table->foreignId('class_section_offering_id')->nullable()->constrained('class_section_offerings')->restrictOnDelete();
            $table->foreignId('student_profile_id')->nullable()->constrained('student_profiles')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'class_section_offering_id'], 'cbt_exam_assign_offering_unique');
            $table->unique(['exam_id', 'student_profile_id'], 'cbt_exam_assign_student_unique');
            $table->index(['student_profile_id', 'exam_id'], 'cbt_exam_assign_student_exam_index');
        });

        Schema::create('cbt_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exam_id')->constrained('cbt_exams')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->restrictOnDelete();
            $table->string('device_id', 120)->nullable();
            $table->string('status', 20)->default(CbtAttemptStatus::InProgress->value);
            $table->string('mode', 20)->default(CbtAttemptMode::Online->value);
            $table->string('sync_status', 20)->default(CbtSyncStatus::Pending->value);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('client_submitted_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['exam_id', 'student_profile_id'], 'cbt_attempts_exam_student_index');
            $table->index(['user_id', 'status'], 'cbt_attempts_user_status_index');
            $table->index(['sync_status', 'updated_at'], 'cbt_attempts_sync_index');
        });

        Schema::create('cbt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('cbt_attempts')->restrictOnDelete();
            $table->foreignId('exam_question_id')->constrained('cbt_exam_questions')->restrictOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('cbt_questions')->restrictOnDelete();
            $table->foreignId('selected_exam_option_id')->nullable()->constrained('cbt_exam_question_options')->restrictOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('client_answered_at')->nullable();
            $table->string('sync_status', 20)->default(CbtSyncStatus::Pending->value);
            $table->timestamps();

            $table->unique(['attempt_id', 'exam_question_id'], 'cbt_answers_attempt_exam_question_unique');
            $table->index(['exam_question_id'], 'cbt_answers_exam_question_index');
        });

        Schema::create('cbt_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->unique()->constrained('cbt_attempts')->restrictOnDelete();
            $table->decimal('score', 8, 2);
            $table->decimal('max_score', 8, 2);
            $table->decimal('percentage', 8, 2);
            $table->string('grade', 20)->nullable();
            $table->boolean('passed')->default(false);
            $table->timestamp('marked_at')->nullable();
            $table->foreignId('assessment_score_id')->nullable()->unique()->constrained('assessment_scores')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cbt_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->nullable()->constrained('cbt_attempts')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('direction', 32)->default(CbtSyncDirection::ClientToServer->value);
            $table->string('payload_hash', 64)->nullable();
            $table->string('status', 20)->default(CbtSyncLogStatus::Accepted->value);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['attempt_id', 'created_at'], 'cbt_sync_logs_attempt_created_index');
            $table->index(['user_id', 'created_at'], 'cbt_sync_logs_user_created_index');
            $table->index(['payload_hash'], 'cbt_sync_logs_payload_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_sync_logs');
        Schema::dropIfExists('cbt_results');
        Schema::dropIfExists('cbt_answers');
        Schema::dropIfExists('cbt_attempts');
        Schema::dropIfExists('cbt_exam_assignments');
        Schema::dropIfExists('cbt_exam_question_options');
        Schema::dropIfExists('cbt_exam_questions');
        Schema::dropIfExists('cbt_exams');
        Schema::dropIfExists('cbt_question_options');
        Schema::dropIfExists('cbt_questions');
    }
};
