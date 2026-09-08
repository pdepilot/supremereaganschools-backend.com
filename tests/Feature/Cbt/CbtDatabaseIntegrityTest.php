<?php

namespace Tests\Feature\Cbt;

use App\Enums\CbtAttemptMode;
use App\Enums\CbtAttemptStatus;
use App\Enums\CbtExamStatus;
use App\Enums\CbtQuestionDifficulty;
use App\Enums\CbtQuestionType;
use App\Enums\CbtSyncStatus;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtExamQuestionOption;
use App\Models\CbtQuestion;
use App\Models\CbtQuestionOption;
use App\Models\CbtResult;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class CbtDatabaseIntegrityTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_cbt_tables_are_created_and_models_persist_relationships(): void
    {
        $class = $this->schoolClass();
        $subject = $this->subject();
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering($this->section($class), $session);
        $student = $this->student();
        $user = $student->user;

        $question = CbtQuestion::query()->create([
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'topic' => 'Algebra',
            'difficulty' => CbtQuestionDifficulty::Easy,
            'type' => CbtQuestionType::Mcq,
            'stem' => 'What is 2 + 2?',
            'marks' => 1,
            'is_active' => true,
        ]);

        $bankOption = CbtQuestionOption::query()->create([
            'question_id' => $question->id,
            'label' => 'A',
            'body' => '4',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        $exam = CbtExam::query()->create([
            'title' => 'JSS 1 Maths CBT',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 40,
            'question_count' => 1,
            'max_score' => 1,
            'status' => CbtExamStatus::Draft,
            'write_to_assessment_score' => false,
        ]);

        $examQuestion = CbtExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'question_id' => $question->id,
            'sort_order' => 1,
            'marks' => 1,
            'type' => CbtQuestionType::Mcq,
            'stem' => 'What is 2 + 2?',
            'is_frozen' => false,
        ]);

        $examOption = CbtExamQuestionOption::query()->create([
            'exam_question_id' => $examQuestion->id,
            'source_option_id' => $bankOption->id,
            'label' => 'A',
            'body' => '4',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        $attempt = CbtAttempt::query()->create([
            'uuid' => (string) Str::uuid(),
            'exam_id' => $exam->id,
            'user_id' => $user->id,
            'student_profile_id' => $student->id,
            'status' => CbtAttemptStatus::InProgress,
            'mode' => CbtAttemptMode::Online,
            'sync_status' => CbtSyncStatus::Pending,
            'started_at' => now(),
        ]);

        CbtAnswer::query()->create([
            'attempt_id' => $attempt->id,
            'exam_question_id' => $examQuestion->id,
            'question_id' => $question->id,
            'selected_exam_option_id' => $examOption->id,
            'answered_at' => now(),
            'sync_status' => CbtSyncStatus::Synced,
        ]);

        CbtResult::query()->create([
            'attempt_id' => $attempt->id,
            'score' => 1,
            'max_score' => 1,
            'percentage' => 100,
            'passed' => true,
            'marked_at' => now(),
        ]);

        $this->assertTrue($subject->cbtQuestions()->whereKey($question->id)->exists());
        $this->assertTrue($class->cbtQuestions()->whereKey($question->id)->exists());
        $this->assertTrue($exam->examQuestions()->whereKey($examQuestion->id)->exists());
        $this->assertSame($examQuestion->id, $attempt->answers()->first()->exam_question_id);
        $this->assertTrue($attempt->result()->exists());
        $this->assertFalse($exam->write_to_assessment_score);
    }

    public function test_answers_uniquely_bind_attempt_to_exam_question(): void
    {
        [$attempt, $examQuestion, $examOption] = $this->seedAttemptGraph();

        CbtAnswer::query()->create([
            'attempt_id' => $attempt->id,
            'exam_question_id' => $examQuestion->id,
            'selected_exam_option_id' => $examOption->id,
            'sync_status' => CbtSyncStatus::Pending,
        ]);

        $this->expectException(QueryException::class);

        CbtAnswer::query()->create([
            'attempt_id' => $attempt->id,
            'exam_question_id' => $examQuestion->id,
            'selected_exam_option_id' => $examOption->id,
            'sync_status' => CbtSyncStatus::Pending,
        ]);
    }

    public function test_foreign_keys_prevent_deleting_exam_question_used_by_answers(): void
    {
        [$attempt, $examQuestion, $examOption] = $this->seedAttemptGraph();

        CbtAnswer::query()->create([
            'attempt_id' => $attempt->id,
            'exam_question_id' => $examQuestion->id,
            'selected_exam_option_id' => $examOption->id,
            'sync_status' => CbtSyncStatus::Pending,
        ]);

        $this->expectException(QueryException::class);
        $examQuestion->delete();
    }

    public function test_foreign_keys_prevent_force_deleting_exam_with_attempts(): void
    {
        [$attempt] = $this->seedAttemptGraph();

        $this->expectException(QueryException::class);
        $attempt->exam->forceDelete();
    }

    public function test_results_assessment_score_link_is_unique(): void
    {
        [$attempt] = $this->seedAttemptGraph();
        $enrollment = $this->enroll($attempt->studentProfile, $attempt->exam->classSectionOffering);
        $catalogue = $this->assessmentCatalogue();
        $score = \App\Models\AssessmentScore::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_id' => $attempt->exam->term_id,
            'subject_id' => $attempt->exam->subject_id,
            'assessment_type_id' => $catalogue['types']['examination']->id,
            'score' => 80,
        ]);

        CbtResult::query()->create([
            'attempt_id' => $attempt->id,
            'score' => 80,
            'max_score' => 100,
            'percentage' => 80,
            'passed' => true,
            'assessment_score_id' => $score->id,
        ]);

        $secondAttempt = CbtAttempt::query()->create([
            'uuid' => (string) Str::uuid(),
            'exam_id' => $attempt->exam_id,
            'user_id' => $attempt->user_id,
            'student_profile_id' => $attempt->student_profile_id,
            'status' => CbtAttemptStatus::Submitted,
            'mode' => CbtAttemptMode::Online,
            'sync_status' => CbtSyncStatus::Synced,
        ]);

        $this->expectException(QueryException::class);

        CbtResult::query()->create([
            'attempt_id' => $secondAttempt->id,
            'score' => 80,
            'max_score' => 100,
            'percentage' => 80,
            'passed' => true,
            'assessment_score_id' => $score->id,
        ]);
    }

    /**
     * @return array{0: CbtAttempt, 1: CbtExamQuestion, 2: CbtExamQuestionOption}
     */
    private function seedAttemptGraph(): array
    {
        $level = $this->level(['slug' => 'jss-'.random_int(1000, 9999), 'name' => 'Junior Secondary '.random_int(1000, 9999)]);
        $class = $this->schoolClass($level, ['short_code' => 'J'.random_int(10, 99)]);
        $subject = $this->subject(['code' => 'MTH-'.random_int(100, 999)]);
        $session = $this->academicSession(['name' => '2025/2026-'.random_int(100, 999)]);
        $term = $this->termFor($session);
        $offering = $this->offering($this->section($class, ['arm' => 'B', 'name' => $class->name.' B']), $session);
        $student = $this->student();

        $question = CbtQuestion::query()->create([
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'stem' => 'Sample stem',
            'marks' => 1,
            'type' => CbtQuestionType::Mcq,
            'difficulty' => CbtQuestionDifficulty::Medium,
        ]);

        $exam = CbtExam::query()->create([
            'title' => 'Integrity Exam',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 30,
            'status' => CbtExamStatus::Published,
            'published_at' => now(),
        ]);

        $examQuestion = CbtExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'question_id' => $question->id,
            'sort_order' => 1,
            'marks' => 1,
            'type' => CbtQuestionType::Mcq,
            'stem' => 'Sample stem',
            'is_frozen' => true,
            'frozen_at' => now(),
        ]);

        $examOption = CbtExamQuestionOption::query()->create([
            'exam_question_id' => $examQuestion->id,
            'body' => 'Option A',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        $attempt = CbtAttempt::query()->create([
            'uuid' => (string) Str::uuid(),
            'exam_id' => $exam->id,
            'user_id' => $student->user_id,
            'student_profile_id' => $student->id,
            'status' => CbtAttemptStatus::InProgress,
            'mode' => CbtAttemptMode::Online,
            'sync_status' => CbtSyncStatus::Pending,
        ]);

        return [$attempt, $examQuestion, $examOption];
    }
}
