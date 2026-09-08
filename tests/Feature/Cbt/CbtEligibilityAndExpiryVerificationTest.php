<?php

namespace Tests\Feature\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\EnrollmentStatus;
use App\Models\CbtAttempt;
use App\Models\CbtResult;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtExamAssignmentService;
use App\Services\Cbt\CbtExamPublishService;
use App\Services\Cbt\CbtExamService;
use App\Services\Cbt\CbtExamSnapshotService;
use App\Services\Cbt\CbtSubmissionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesCbtContext;
use Tests\TestCase;

class CbtEligibilityAndExpiryVerificationTest extends TestCase
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

    public function test_class_offering_assignment_makes_actively_enrolled_student_eligible(): void
    {
        $ctx = $this->cbtPublishedExam();
        $assignments = app(CbtExamAssignmentService::class);

        $this->assertTrue($assignments->isStudentEligible($ctx['exam'], $ctx['student']));

        $started = app(CbtAttemptService::class)->start(
            $ctx['exam'],
            $ctx['student']->user,
            $ctx['student'],
        );

        $this->assertSame(CbtAttemptStatus::InProgress, $started->status);
        $this->assertSame($ctx['student']->id, $started->student_profile_id);
    }

    public function test_student_on_different_offering_is_not_eligible(): void
    {
        $ctx = $this->cbtPublishedExam();
        $otherCampus = $this->campus(['name' => 'Other '.random_int(10000, 99999)]);
        $otherLevel = $this->level(['slug' => 'other-'.random_int(10000, 99999), 'name' => 'Other '.random_int(1000, 9999)]);
        $otherClass = $this->schoolClass($otherLevel, ['short_code' => 'OX'.random_int(10, 99)]);
        $otherOffering = $this->offering(
            $this->section($otherClass, ['arm' => 'Z', 'name' => $otherClass->name.' Z']),
            $ctx['exam']->academicSession,
            $otherCampus,
        );

        $outsider = $this->student();
        $this->enroll($outsider, $otherOffering);

        $this->assertFalse(app(CbtExamAssignmentService::class)->isStudentEligible($ctx['exam'], $outsider));

        $this->expectException(ValidationException::class);
        app(CbtAttemptService::class)->start($ctx['exam'], $outsider->user, $outsider);
    }

    public function test_inactive_enrollment_on_assigned_offering_is_not_eligible(): void
    {
        $ctx = $this->cbtPublishedExam();
        $enrollment = $ctx['student']->enrollments()->where('class_section_offering_id', $ctx['offering']->id)->firstOrFail();
        $enrollment->update([
            'status' => EnrollmentStatus::Completed,
            'left_on' => now()->toDateString(),
        ]);

        $this->assertFalse(app(CbtExamAssignmentService::class)->isStudentEligible($ctx['exam']->fresh(['assignments']), $ctx['student']));

        $this->expectException(ValidationException::class);
        app(CbtAttemptService::class)->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
    }

    public function test_explicit_individual_assignment_is_eligible_without_class_assignment(): void
    {
        $session = $this->academicSession(['name' => 'ind-'.random_int(10000, 99999)]);
        $term = $this->termFor($session);
        $level = $this->level(['slug' => 'ind-'.random_int(10000, 99999)]);
        $class = $this->schoolClass($level);
        $campus = $this->campus(['name' => 'Ind '.random_int(10000, 99999)]);
        $offering = $this->offering($this->section($class), $session, $campus);
        $subject = $this->subject(['name' => 'Ind Sub '.random_int(10000, 99999), 'code' => 'IN'.random_int(100, 999)]);
        $student = $this->student();
        $this->enroll($student, $offering);
        $question = $this->cbtBankQuestion(['school_class' => $class, 'subject' => $subject]);

        $exam = app(CbtExamService::class)->createDraft([
            'title' => 'Individual only',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 20,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);
        app(CbtExamSnapshotService::class)->attach($exam, $question);
        app(CbtExamAssignmentService::class)->assignToStudent($exam, $student->id);
        $exam = app(CbtExamPublishService::class)->publish($exam->fresh());

        $this->assertTrue(app(CbtExamAssignmentService::class)->isStudentEligible($exam, $student));

        $attempt = app(CbtAttemptService::class)->start($exam, $student->user, $student);
        $this->assertSame($student->id, $attempt->student_profile_id);
    }

    public function test_duplicate_class_and_student_assignments_are_prevented(): void
    {
        $session = $this->academicSession(['name' => 'dup-'.random_int(10000, 99999)]);
        $term = $this->termFor($session);
        $level = $this->level(['slug' => 'dup-'.random_int(10000, 99999)]);
        $class = $this->schoolClass($level);
        $campus = $this->campus(['name' => 'Dup '.random_int(10000, 99999)]);
        $offering = $this->offering($this->section($class), $session, $campus);
        $subject = $this->subject(['name' => 'Dup Sub '.random_int(10000, 99999), 'code' => 'DU'.random_int(100, 999)]);
        $student = $this->student();

        $exam = app(CbtExamService::class)->createDraft([
            'title' => 'Dup exam',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 15,
        ]);

        $assignments = app(CbtExamAssignmentService::class);
        $assignments->assignToOffering($exam, $offering->id);
        $assignments->assignToStudent($exam, $student->id);

        try {
            $assignments->assignToOffering($exam, $offering->id);
            $this->fail('Expected duplicate class assignment to fail');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assignment', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $assignments->assignToStudent($exam, $student->id);
    }

    public function test_expired_attempt_auto_finalizes_once_preserving_ends_at_and_answers(): void
    {
        Carbon::setTestNow('2026-09-07 10:00:00');

        try {
            $ctx = $this->cbtPublishedExam([
                'max_attempts' => 2,
                'duration_minutes' => 10,
                'starts_at' => Carbon::parse('2026-09-07 09:00:00'),
                'ends_at' => Carbon::parse('2026-09-07 20:00:00'),
            ]);

            $attempts = app(CbtAttemptService::class);
            $answers = app(CbtAnswerService::class);
            $submissions = app(CbtSubmissionService::class);

            $oldUuid = (string) Str::uuid();
            $expired = $attempts->start($ctx['exam'], $ctx['student']->user, $ctx['student'], [
                'uuid' => $oldUuid,
            ]);

            $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();
            $answers->save($expired, $ctx['student']->user, [
                'exam_question_id' => $ctx['examQuestion']->id,
                'selected_exam_option_id' => $correct->id,
            ]);

            $originalEndsAt = $expired->ends_at?->copy();
            $this->assertNotNull($originalEndsAt);

            Carbon::setTestNow('2026-09-07 10:15:00');

            $second = $attempts->start($ctx['exam'], $ctx['student']->user, $ctx['student'], [
                'uuid' => (string) Str::uuid(),
            ]);

            $expired = $expired->fresh(['result', 'answers']);
            $this->assertSame(CbtAttemptStatus::Submitted, $expired->status);
            $this->assertTrue($originalEndsAt->equalTo($expired->ends_at));
            $this->assertNotNull($expired->result);
            $this->assertSame(2.0, (float) $expired->result->score);
            $this->assertSame(1, $expired->answers()->count());
            $this->assertNotSame($expired->id, $second->id);
            $this->assertSame(CbtAttemptStatus::InProgress, $second->status);

            $historicalScore = (float) $expired->result->score;
            $historicalResultId = $expired->result->id;

            $retry = $submissions->submit($expired, $ctx['student']->user);
            $this->assertSame($historicalResultId, $retry->id);
            $this->assertSame($historicalScore, (float) $retry->score);
            $this->assertSame(1, CbtResult::query()->where('attempt_id', $expired->id)->count());

            // Sync-style retry of the old attempt uuid must return the same historical attempt.
            $viaUuid = $attempts->start($ctx['exam'], $ctx['student']->user, $ctx['student'], [
                'uuid' => $oldUuid,
            ]);
            $this->assertSame($expired->id, $viaUuid->id);
            $this->assertSame($historicalResultId, $viaUuid->result->id);
            $this->assertSame($historicalScore, (float) $viaUuid->result->fresh()->score);
            $this->assertTrue($originalEndsAt->equalTo($viaUuid->ends_at));

            // New attempt remains independent; historical result untouched.
            $this->assertSame(1, CbtResult::query()->where('attempt_id', $expired->id)->count());
            $this->assertSame(2, CbtAttempt::query()->where('exam_id', $ctx['exam']->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_auto_finalized_attempt_cannot_accept_late_answer_sync(): void
    {
        Carbon::setTestNow('2026-09-07 11:00:00');

        try {
            $ctx = $this->cbtPublishedExam([
                'max_attempts' => 2,
                'duration_minutes' => 5,
                'starts_at' => Carbon::parse('2026-09-07 10:00:00'),
                'ends_at' => Carbon::parse('2026-09-07 20:00:00'),
            ]);

            $attempts = app(CbtAttemptService::class);
            $old = $attempts->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
            $wrong = $ctx['examQuestion']->options()->where('is_correct', false)->firstOrFail();

            Carbon::setTestNow('2026-09-07 11:10:00');
            $attempts->start($ctx['exam'], $ctx['student']->user, $ctx['student'], [
                'uuid' => (string) Str::uuid(),
            ]);

            $old = $old->fresh();
            $this->assertSame(CbtAttemptStatus::Submitted, $old->status);

            $this->expectException(ValidationException::class);
            app(CbtAnswerService::class)->save($old, $ctx['student']->user, [
                'exam_question_id' => $ctx['examQuestion']->id,
                'selected_exam_option_id' => $wrong->id,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
