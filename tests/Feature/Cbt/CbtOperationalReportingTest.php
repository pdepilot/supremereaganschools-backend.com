<?php

namespace Tests\Feature\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\OnlinePaymentPurpose;
use App\Enums\OnlinePaymentStatus;
use App\Enums\RoleSlug;
use App\Models\CbtExamQuestionOption;
use App\Models\CbtResultAccess;
use App\Models\OnlinePayment;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtSubmissionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesCbtContext;
use Tests\TestCase;

class CbtOperationalReportingTest extends TestCase
{
    use CreatesAcademicContext;
    use CreatesCbtContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->assessmentCatalogue();
        $this->settings(['cbt_result_details_require_payment' => true]);
    }

    public function test_dashboard_is_authorized_and_counts_accurately(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $student = $this->userWithRole(RoleSlug::Student);
        $ctx = $this->submittedResultContext();

        $this->actingAsCbt($student)->getJson('/api/v1/cbt/admin')->assertForbidden();

        $summary = $this->actingAsCbt($officer)->getJson('/api/v1/cbt/admin')->assertOk()->json('data.summary');
        $this->assertGreaterThanOrEqual(1, $summary['published_exams']);
        $this->assertGreaterThanOrEqual(1, $summary['completed_attempts']);
        $this->assertGreaterThanOrEqual(1, $summary['results_generated']);
        $this->assertGreaterThanOrEqual(1, $summary['locked_results']);
        $this->assertSame(0, $summary['result_checker']['payments_paid']);
        $this->assertArrayHasKey('id', ['id' => $ctx['result']->id]);
    }

    public function test_monitor_and_exam_report_counts_and_question_analytics(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $ctx = $this->submittedResultContext();
        $exam = $ctx['exam'];

        // Second assigned student who has not started
        $other = $this->student();
        $this->enroll($other, $ctx['offering']);
        app(\App\Services\Cbt\CbtExamAssignmentService::class)->assignToStudent($exam, $other->id);

        $monitor = $this->actingAsCbt($officer)->getJson('/api/v1/cbt/admin/attempts/monitor')->assertOk();
        $row = collect($monitor->json('data.exams'))->firstWhere('exam_id', $exam->id);
        // Active window depends on exam schedule; report endpoint always works.
        $report = $this->actingAsCbt($officer)->getJson('/api/v1/cbt/admin/exams/'.$exam->id.'/report')->assertOk();
        $perf = $report->json('data.performance');
        $this->assertGreaterThanOrEqual(2, $perf['assigned_students']);
        $this->assertSame(1, $perf['started_students']);
        $this->assertSame(1, $perf['attempts_submitted']);
        $this->assertSame(1, $perf['not_started_students']);
        $this->assertSame(100.0, (float) $perf['average_percentage']);

        $questions = $report->json('data.questions');
        $this->assertNotEmpty($questions);
        $this->assertSame(1, $questions[0]['correct']);
        $this->assertSame(0, $questions[0]['incorrect']);
        $this->assertSame(0, $questions[0]['unanswered']);
        $this->assertArrayNotHasKey('is_correct', $questions[0]);
        $this->assertStringNotContainsString('Abuja', json_encode($questions)); // no answer key leakage of option bodies as "correct"

        $roster = collect($report->json('data.roster'));
        $this->assertTrue($roster->contains(fn ($r) => $r['status'] === 'submitted'));
        $this->assertTrue($roster->contains(fn ($r) => $r['status'] === 'not_started'));
    }

    public function test_attempts_paginated_and_multiple_attempts_do_not_inflate_students(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $ctx = $this->cbtPublishedExam(['max_attempts' => 2]);
        $user = $ctx['student']->user;

        $first = app(CbtAttemptService::class)->start($ctx['exam'], $user, $ctx['student']);
        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();
        app(CbtAnswerService::class)->save($first, $user, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ]);
        app(CbtSubmissionService::class)->submit($first, $user);

        $second = app(CbtAttemptService::class)->start($ctx['exam'], $user, $ctx['student']);
        app(CbtAnswerService::class)->save($second, $user, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ]);
        app(CbtSubmissionService::class)->submit($second, $user);

        $list = $this->actingAsCbt($officer)
            ->getJson('/api/v1/cbt/admin/attempts?exam_id='.$ctx['exam']->id)
            ->assertOk();
        $this->assertSame(2, $list->json('data.meta.total'));
        $this->assertArrayHasKey('current_page', $list->json('data.meta'));

        $perf = $this->actingAsCbt($officer)
            ->getJson('/api/v1/cbt/admin/exams/'.$ctx['exam']->id.'/report')
            ->json('data.performance');
        $this->assertSame(1, $perf['started_students']);
        $this->assertSame(2, $perf['attempts_submitted']);
        $this->assertSame(1, $perf['completed_students']);
    }

    public function test_question_bank_edit_does_not_change_historical_analytics(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $ctx = $this->submittedResultContext();
        $before = $this->actingAsCbt($officer)
            ->getJson('/api/v1/cbt/admin/exams/'.$ctx['exam']->id.'/report')
            ->json('data.questions.0');

        // Mutate live bank question stem/options — snapshot must remain authoritative.
        $ctx['question']->update(['stem' => 'CHANGED BANK STEM']);
        CbtExamQuestionOption::query()->where('exam_question_id', $ctx['examQuestion']->id)->update(['body' => 'CHANGED OPTION']);

        $after = $this->actingAsCbt($officer)
            ->getJson('/api/v1/cbt/admin/exams/'.$ctx['exam']->id.'/report')
            ->json('data.questions.0');

        $this->assertSame($before['stem_truncated'], $after['stem_truncated']);
        $this->assertSame($before['correct'], $after['correct']);
        $this->assertStringNotContainsString('CHANGED BANK STEM', $after['stem_truncated']);
    }

    public function test_result_checker_status_and_revenue_only_count_paid(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $ctx = $this->submittedResultContext();

        OnlinePayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'SRS-PAY-FAIL1',
            'provider' => 'paystack',
            'purpose' => OnlinePaymentPurpose::CbtResultChecker,
            'user_id' => $ctx['user']->id,
            'student_profile_id' => $ctx['student']->id,
            'email' => $ctx['user']->email,
            'amount_kobo' => 50000,
            'currency' => 'NGN',
            'status' => OnlinePaymentStatus::Failed,
            'metadata' => ['cbt_result_id' => $ctx['result']->id],
        ]);
        $paid = OnlinePayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'SRS-PAY-OK1',
            'provider' => 'paystack',
            'purpose' => OnlinePaymentPurpose::CbtResultChecker,
            'user_id' => $ctx['user']->id,
            'student_profile_id' => $ctx['student']->id,
            'email' => $ctx['user']->email,
            'amount_kobo' => 50000,
            'currency' => 'NGN',
            'status' => OnlinePaymentStatus::Paid,
            'paid_at' => now(),
            'metadata' => ['cbt_result_id' => $ctx['result']->id],
        ]);
        CbtResultAccess::query()->create([
            'cbt_result_id' => $ctx['result']->id,
            'student_profile_id' => $ctx['student']->id,
            'online_payment_id' => $paid->id,
            'granted_at' => now(),
        ]);

        $summary = $this->actingAsCbt($officer)->getJson('/api/v1/cbt/admin')->json('data.summary');
        $this->assertSame(1, $summary['result_checker']['payments_paid']);
        $this->assertSame(1, $summary['result_checker']['payments_failed']);
        $this->assertSame(50000, $summary['result_checker']['revenue_kobo']);
        $this->assertSame(1, $summary['unlocked_results']);

        $history = $this->actingAsCbt($officer)
            ->getJson('/api/v1/cbt/admin/reports/student-history?student_id='.$ctx['student']->id)
            ->assertOk()
            ->json('data.items.0');
        $this->assertSame('unlocked', $history['result_access']);
        $this->assertSame('paid', $history['payment_status']);
    }

    public function test_exports_and_security_boundaries(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $studentUser = $this->userWithRole(RoleSlug::Student);
        $ctx = $this->submittedResultContext();

        $this->actingAsCbt($studentUser)
            ->getJson('/api/v1/cbt/admin/exams/'.$ctx['exam']->id.'/report')
            ->assertForbidden();

        $this->actingAsCbt($officer)
            ->get('/api/v1/cbt/admin/exams/'.$ctx['exam']->id.'/export/results')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAsCbt($studentUser)
            ->get('/api/v1/cbt/admin/exams/'.$ctx['exam']->id.'/export/results')
            ->assertForbidden();

        $other = $this->submittedResultContext();
        $this->actingAsCbt($ctx['user'])
            ->getJson('/api/v1/cbt/results/'.$other['result']->id.'/detailed')
            ->assertForbidden();
    }

    public function test_expired_in_progress_is_reported_without_rewriting_timestamps(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $ctx = $this->cbtPublishedExam();
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
        $originalEnds = $attempt->ends_at->copy();
        $attempt->forceFill(['ends_at' => now()->subMinute()])->save();

        $list = $this->actingAsCbt($officer)
            ->getJson('/api/v1/cbt/admin/attempts?exam_id='.$ctx['exam']->id.'&status=expired')
            ->assertOk();
        $this->assertSame(1, $list->json('data.meta.total'));
        $this->assertSame('expired', $list->json('data.items.0.status'));
        $this->assertTrue($attempt->fresh()->ends_at->equalTo($attempt->ends_at));
        $this->assertSame(CbtAttemptStatus::InProgress, $attempt->fresh()->status);
        $this->assertTrue($originalEnds->ne($attempt->fresh()->ends_at));
    }

    public function test_admin_can_extend_reset_and_update_published_schedule(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $studentUser = $this->userWithRole(RoleSlug::Student);
        $ctx = $this->cbtPublishedExam(['duration_minutes' => 30]);
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
        $endsBefore = $attempt->ends_at->copy();

        $this->actingAsCbt($studentUser)
            ->postJson('/api/v1/cbt/admin/attempts/'.$attempt->id.'/extend', ['minutes' => 10])
            ->assertForbidden();

        $extended = $this->actingAsCbt($officer)
            ->postJson('/api/v1/cbt/admin/attempts/'.$attempt->id.'/extend', ['minutes' => 10])
            ->assertOk()
            ->json('data.attempt');
        $this->assertTrue($attempt->fresh()->ends_at->gt($endsBefore));
        $this->assertTrue($extended['can_extend_timer']);

        // Operationally expired in-progress can be reopened by extend/reset.
        $attempt->forceFill(['ends_at' => now()->subMinutes(5)])->save();
        $this->actingAsCbt($officer)
            ->postJson('/api/v1/cbt/admin/attempts/'.$attempt->id.'/extend', ['reset' => true])
            ->assertOk();
        $this->assertTrue($attempt->fresh()->ends_at->gt(now()->addMinutes(25)));

        $other = $this->student();
        $this->enroll($other, $ctx['offering']);
        app(\App\Services\Cbt\CbtExamAssignmentService::class)->assignToStudent($ctx['exam'], $other->id);
        $second = app(CbtAttemptService::class)->start($ctx['exam'], $other->user, $other);
        $secondEnds = $second->ends_at->copy();

        $bulk = $this->actingAsCbt($officer)
            ->postJson('/api/v1/cbt/admin/exams/'.$ctx['exam']->id.'/extend-timers', ['minutes' => 5])
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $bulk['updated']);
        $this->assertTrue($second->fresh()->ends_at->gt($secondEnds));

        $this->actingAsCbt($officer)
            ->postJson('/api/v1/cbt/admin/exams/'.$ctx['exam']->id.'/schedule', [
                'duration_minutes' => 45,
            ])
            ->assertOk();
        $this->assertSame(45, (int) $ctx['exam']->fresh()->duration_minutes);
        // Existing live timers are not rewritten by schedule updates.
        $this->assertTrue($attempt->fresh()->ends_at->lt(now()->addMinutes(40)));

        $thirdStudent = $this->student();
        $this->enroll($thirdStudent, $ctx['offering']);
        app(\App\Services\Cbt\CbtExamAssignmentService::class)->assignToStudent($ctx['exam'], $thirdStudent->id);
        $future = app(CbtAttemptService::class)->start($ctx['exam'], $thirdStudent->user, $thirdStudent);
        $this->assertTrue($future->ends_at->gte(now()->addMinutes(44)));
    }

    /**
     * @return array{exam: \App\Models\CbtExam, offering: \App\Models\ClassSectionOffering, user: \App\Models\User, student: \App\Models\StudentProfile, result: \App\Models\CbtResult, question: \App\Models\CbtQuestion, examQuestion: \App\Models\CbtExamQuestion}
     */
    private function submittedResultContext(): array
    {
        $ctx = $this->cbtPublishedExam();
        $user = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $user, $ctx['student']);
        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();
        app(CbtAnswerService::class)->save($attempt, $user, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ]);
        $result = app(CbtSubmissionService::class)->submit($attempt, $user);

        return [
            'exam' => $ctx['exam'],
            'offering' => $ctx['offering'],
            'user' => $user,
            'student' => $ctx['student'],
            'result' => $result,
            'question' => $ctx['question'],
            'examQuestion' => $ctx['examQuestion'],
        ];
    }
}
