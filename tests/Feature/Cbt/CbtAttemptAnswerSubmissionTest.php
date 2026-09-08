<?php

namespace Tests\Feature\Cbt;

use App\Models\CbtAttempt;
use App\Models\CbtResult;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtExamAssignmentService;
use App\Services\Cbt\CbtExamPublishService;
use App\Services\Cbt\CbtExamService;
use App\Services\Cbt\CbtExamSnapshotService;
use App\Services\Cbt\CbtQuestionBankService;
use App\Services\Cbt\CbtSubmissionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesCbtContext;
use Tests\TestCase;

class CbtAttemptAnswerSubmissionTest extends TestCase
{
    use CreatesAcademicContext;
    use CreatesCbtContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->assessmentCatalogue();
    }

    public function test_class_and_individual_assignment_with_duplicate_prevention(): void
    {
        $session = $this->academicSession(['name' => 'asg-'.random_int(1000, 9999)]);
        $term = $this->termFor($session);
        $level = $this->level(['slug' => 'asg-'.random_int(1000, 9999)]);
        $class = $this->schoolClass($level);
        $offering = $this->offering($this->section($class), $session);
        $subject = $this->subject(['code' => 'AS'.random_int(100, 999)]);
        $student = $this->student();

        $exam = app(CbtExamService::class)->createDraft([
            'title' => 'Assign exam',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 15,
        ]);

        $assignments = app(CbtExamAssignmentService::class);
        $assignments->assignToOffering($exam, $offering->id);
        $assignments->assignToStudent($exam, $student->id);

        $this->expectException(ValidationException::class);
        $assignments->assignToOffering($exam, $offering->id);
    }

    public function test_eligible_student_can_start_ineligible_cannot(): void
    {
        $ctx = $this->cbtPublishedExam();
        $attempts = app(CbtAttemptService::class);

        $started = $attempts->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
        $this->assertNotNull($started->started_at);
        $this->assertNotNull($started->ends_at);
        $this->assertTrue($started->ends_at->equalTo(
            $started->started_at->copy()->addMinutes((int) $ctx['exam']->duration_minutes)
        ));

        $outsider = $this->student();
        $this->expectException(ValidationException::class);
        $attempts->start($ctx['exam'], $outsider->user, $outsider);
    }

    public function test_unpublished_exam_cannot_be_started(): void
    {
        $session = $this->academicSession(['name' => 'up-'.random_int(1000, 9999)]);
        $term = $this->termFor($session);
        $level = $this->level(['slug' => 'up-'.random_int(1000, 9999)]);
        $class = $this->schoolClass($level);
        $offering = $this->offering($this->section($class), $session);
        $subject = $this->subject(['code' => 'UP'.random_int(100, 999)]);
        $student = $this->student();
        $this->enroll($student, $offering);
        $question = $this->cbtBankQuestion(['school_class' => $class, 'subject' => $subject]);

        $exam = app(CbtExamService::class)->createDraft([
            'title' => 'Draft only',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 10,
        ]);
        app(CbtExamSnapshotService::class)->attach($exam, $question);
        app(CbtExamAssignmentService::class)->assignToOffering($exam, $offering->id);

        $this->expectException(ValidationException::class);
        app(CbtAttemptService::class)->start($exam, $student->user, $student);
    }

    public function test_max_attempts_and_active_attempt_rules(): void
    {
        $ctx = $this->cbtPublishedExam(['max_attempts' => 1]);
        $attempts = app(CbtAttemptService::class);
        $submissions = app(CbtSubmissionService::class);

        $first = $attempts->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
        $submissions->submit($first, $ctx['student']->user);

        $this->expectException(ValidationException::class);
        $attempts->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
    }

    public function test_uuid_start_is_idempotent(): void
    {
        $ctx = $this->cbtPublishedExam();
        $uuid = (string) Str::uuid();
        $attempts = app(CbtAttemptService::class);

        $a = $attempts->start($ctx['exam'], $ctx['student']->user, $ctx['student'], ['uuid' => $uuid]);
        $b = $attempts->start($ctx['exam'], $ctx['student']->user, $ctx['student'], ['uuid' => $uuid]);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, CbtAttempt::query()->where('exam_id', $ctx['exam']->id)->count());
    }

    public function test_answers_validate_option_ownership_and_upsert(): void
    {
        $ctx = $this->cbtPublishedExam();
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
        $answers = app(CbtAnswerService::class);
        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();
        $wrong = $ctx['examQuestion']->options()->where('is_correct', false)->firstOrFail();

        $first = $answers->save($attempt, $ctx['student']->user, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $wrong->id,
        ]);
        $second = $answers->save($attempt, $ctx['student']->user, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($correct->id, $second->selected_exam_option_id);
        $this->assertSame(1, $attempt->answers()->count());

        $otherQuestion = $this->cbtBankQuestion([
            'school_class' => $ctx['question']->schoolClass,
            'subject' => $ctx['question']->subject,
            'stem' => 'Other',
        ]);

        // Craft an option from another exam snapshot to ensure rejection.
        $otherExam = app(CbtExamService::class)->createDraft([
            'title' => 'Other draft',
            'subject_id' => $ctx['exam']->subject_id,
            'class_section_offering_id' => $ctx['exam']->class_section_offering_id,
            'academic_session_id' => $ctx['exam']->academic_session_id,
            'term_id' => $ctx['exam']->term_id,
            'duration_minutes' => 10,
        ]);
        $otherEq = app(CbtExamSnapshotService::class)->attach($otherExam, $otherQuestion);
        $foreignOption = $otherEq->options()->firstOrFail();

        $this->expectException(ValidationException::class);
        $answers->save($attempt, $ctx['student']->user, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $foreignOption->id,
        ]);
    }

    public function test_submitted_and_expired_attempts_reject_answers(): void
    {
        $ctx = $this->cbtPublishedExam(['duration_minutes' => 5]);
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
        $option = $ctx['examQuestion']->options()->firstOrFail();
        $answers = app(CbtAnswerService::class);

        app(CbtSubmissionService::class)->submit($attempt, $ctx['student']->user);

        try {
            $answers->save($attempt->fresh(), $ctx['student']->user, [
                'exam_question_id' => $ctx['examQuestion']->id,
                'selected_exam_option_id' => $option->id,
            ]);
            $this->fail('Expected submitted attempt to reject answers');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('attempt', $e->errors());
        }

        Carbon::setTestNow('2026-09-07 10:00:00');

        try {
            $ctx2 = $this->cbtPublishedExam([
                'duration_minutes' => 1,
                'title' => 'Expiring CBT',
                'starts_at' => Carbon::parse('2026-09-07 09:00:00'),
                'ends_at' => Carbon::parse('2026-09-07 18:00:00'),
            ]);
            $expiring = app(CbtAttemptService::class)->start($ctx2['exam'], $ctx2['student']->user, $ctx2['student']);
            Carbon::setTestNow('2026-09-07 10:05:00');

            app(CbtAnswerService::class)->save($expiring->fresh(), $ctx2['student']->user, [
                'exam_question_id' => $ctx2['examQuestion']->id,
                'selected_exam_option_id' => $ctx2['examQuestion']->options()->firstOrFail()->id,
            ]);
            $this->fail('Expected expired attempt to reject answers');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('attempt', $e->errors());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_marking_and_idempotent_submission(): void
    {
        $ctx = $this->cbtPublishedExam(['pass_mark' => 50]);
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();

        app(CbtAnswerService::class)->save($attempt, $ctx['student']->user, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ]);

        $submissions = app(CbtSubmissionService::class);
        $result = $submissions->submit($attempt, $ctx['student']->user, [
            'client_submitted_at' => now()->subMinutes(10),
        ]);

        $this->assertSame(2.0, (float) $result->score);
        $this->assertSame(2.0, (float) $result->max_score);
        $this->assertSame(100.0, (float) $result->percentage);
        $this->assertTrue($result->passed);
        $this->assertSame('A', $result->grade);
        $this->assertNotNull($attempt->fresh()->submitted_at);

        $again = $submissions->submit($attempt->fresh(), $ctx['student']->user);
        $this->assertSame($result->id, $again->id);
        $this->assertSame(1, CbtResult::query()->where('attempt_id', $attempt->id)->count());
    }

    public function test_incorrect_and_blank_answers_score_zero(): void
    {
        $session = $this->academicSession(['name' => 'mk-'.random_int(1000, 9999)]);
        $term = $this->termFor($session);
        $level = $this->level(['slug' => 'mk-'.random_int(1000, 9999)]);
        $class = $this->schoolClass($level);
        $offering = $this->offering($this->section($class), $session);
        $subject = $this->subject(['code' => 'MK'.random_int(100, 999)]);
        $student = $this->student();
        $this->enroll($student, $offering);

        $q1 = $this->cbtBankQuestion(['school_class' => $class, 'subject' => $subject, 'stem' => 'Q1', 'marks' => 2]);
        $q2 = $this->cbtBankQuestion(['school_class' => $class, 'subject' => $subject, 'stem' => 'Q2', 'marks' => 3]);

        $exam = app(CbtExamService::class)->createDraft([
            'title' => 'Marking',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 20,
            'pass_mark' => 40,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);

        $snapshots = app(CbtExamSnapshotService::class);
        $eq1 = $snapshots->attach($exam, $q1);
        $eq2 = $snapshots->attach($exam, $q2);
        app(CbtExamAssignmentService::class)->assignToOffering($exam, $offering->id);
        $exam = app(CbtExamPublishService::class)->publish($exam->fresh());

        $attempt = app(CbtAttemptService::class)->start($exam, $student->user, $student);
        $wrong = $eq1->options()->where('is_correct', false)->firstOrFail();
        app(CbtAnswerService::class)->save($attempt, $student->user, [
            'exam_question_id' => $eq1->id,
            'selected_exam_option_id' => $wrong->id,
        ]);
        // eq2 left unanswered

        $result = app(CbtSubmissionService::class)->submit($attempt, $student->user);

        $this->assertSame(0.0, (float) $result->score);
        $this->assertSame(5.0, (float) $result->max_score);
        $this->assertSame(0.0, (float) $result->percentage);
        $this->assertFalse($result->passed);
        $this->assertSame('F', $result->grade);
    }

    public function test_historical_integrity_after_bank_mutation(): void
    {
        $ctx = $this->cbtPublishedExam();
        $frozenStem = $ctx['examQuestion']->stem;
        $correctBody = $ctx['examQuestion']->options()->where('is_correct', true)->value('body');

        app(CbtQuestionBankService::class)->update($ctx['question'], [
            'stem' => 'Completely different bank stem',
        ], [
            ['body' => 'Now this is correct', 'is_correct' => true, 'sort_order' => 1],
            ['body' => 'Now this is wrong', 'is_correct' => false, 'sort_order' => 2],
        ]);

        $examQuestion = $ctx['examQuestion']->fresh(['options']);
        $this->assertSame($frozenStem, $examQuestion->stem);
        $this->assertSame($correctBody, $examQuestion->options()->where('is_correct', true)->value('body'));

        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
        $frozenCorrect = $examQuestion->options()->where('is_correct', true)->firstOrFail();
        app(CbtAnswerService::class)->save($attempt, $ctx['student']->user, [
            'exam_question_id' => $examQuestion->id,
            'selected_exam_option_id' => $frozenCorrect->id,
        ]);

        $result = app(CbtSubmissionService::class)->submit($attempt, $ctx['student']->user);
        $this->assertSame(2.0, (float) $result->score);
        $this->assertSame(100.0, (float) $result->percentage);

        $again = app(CbtSubmissionService::class)->submit($attempt->fresh(), $ctx['student']->user);
        $this->assertSame($result->id, $again->id);
        $this->assertSame(2.0, (float) $again->score);
    }
}
