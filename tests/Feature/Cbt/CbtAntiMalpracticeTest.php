<?php

namespace Tests\Feature\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\CbtIntegrityEventType;
use App\Enums\CbtSubmissionReason;
use App\Enums\RoleSlug;
use App\Models\CbtExamIntegrityEvent;
use App\Models\CbtResult;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtSubmissionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesCbtContext;
use Tests\TestCase;

class CbtAntiMalpracticeTest extends TestCase
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
    }

    public function test_owner_can_auto_submit_and_it_is_idempotent(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $option = $ctx['examQuestion']->options()->firstOrFail();
        app(CbtAnswerService::class)->save($attempt, $owner, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $option->id,
        ]);

        $eventId = (string) Str::uuid();
        $first = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/auto-submit', [
            'integrity_event_id' => $eventId,
            'correlation_id' => $eventId,
            'trigger' => 'tab_hidden',
            'client_submitted_at' => now()->toIso8601String(),
        ])->assertOk();

        $this->assertSame(CbtAttemptStatus::Submitted, $attempt->fresh()->status);
        $this->assertSame(CbtSubmissionReason::AutoSubmittedExamExit->value, $attempt->fresh()->submission_reason);
        $this->assertSame(1, CbtResult::query()->where('attempt_id', $attempt->id)->count());
        $this->assertTrue(CbtExamIntegrityEvent::query()->where('event_id', $eventId)->exists());
        $this->assertTrue(CbtExamIntegrityEvent::query()
            ->where('attempt_id', $attempt->id)
            ->where('event_type', CbtIntegrityEventType::AutoSubmitCompleted)
            ->exists());

        $second = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/auto-submit', [
            'integrity_event_id' => $eventId,
            'correlation_id' => $eventId,
            'trigger' => 'tab_hidden',
        ])->assertOk();

        $this->assertTrue($second->json('data.already_submitted'));
        $this->assertSame(1, CbtResult::query()->where('attempt_id', $attempt->id)->count());
        $this->assertSame(1, CbtExamIntegrityEvent::query()->where('event_id', $eventId)->count());
        $this->assertArrayNotHasKey('is_correct', $first->json('data'));
        $this->assertStringNotContainsString('answer_key', $first->getContent());
    }

    public function test_security_boundaries_for_auto_submit(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $payload = [
            'integrity_event_id' => (string) Str::uuid(),
            'trigger' => 'window_blur',
        ];

        $this->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/auto-submit', $payload)
            ->assertUnauthorized();

        $intruder = $this->userWithRole(RoleSlug::Student);
        $this->actingAsCbt($intruder)
            ->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/auto-submit', $payload)
            ->assertNotFound();

        $this->assertSame(CbtAttemptStatus::InProgress, $attempt->fresh()->status);
        $this->assertSame(0, CbtExamIntegrityEvent::query()->where('attempt_id', $attempt->id)->count());
    }

    public function test_submitted_attempt_cannot_be_reopened_by_auto_submit(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();
        app(CbtAnswerService::class)->save($attempt, $owner, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ]);
        app(CbtSubmissionService::class)->submit($attempt, $owner, [
            'reason' => CbtSubmissionReason::StudentManual,
        ]);

        $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/auto-submit', [
            'integrity_event_id' => (string) Str::uuid(),
            'trigger' => 'fullscreen_exit',
        ])->assertOk()->assertJsonPath('data.already_submitted', true);

        $this->assertSame(CbtSubmissionReason::StudentManual->value, $attempt->fresh()->submission_reason);
        $this->assertSame(CbtAttemptStatus::Submitted, $attempt->fresh()->status);
        $this->assertSame(1, CbtResult::query()->where('attempt_id', $attempt->id)->count());
    }

    public function test_auto_submit_before_deadline_and_cannot_extend_ends_at(): void
    {
        Carbon::setTestNow('2026-09-09 10:00:00');
        $ctx = $this->cbtPublishedExam(['duration_minutes' => 30]);
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $endsAt = $attempt->ends_at->copy();

        $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/auto-submit', [
            'integrity_event_id' => (string) Str::uuid(),
            'trigger' => 'tab_hidden',
            'client_submitted_at' => '2026-09-09T11:00:00+00:00',
        ])->assertOk();

        $this->assertTrue($attempt->fresh()->ends_at->equalTo($endsAt));
        $this->assertSame(CbtSubmissionReason::AutoSubmittedExamExit->value, $attempt->fresh()->submission_reason);
        Carbon::setTestNow();
    }

    public function test_integrity_triggers_are_recorded_without_duplicate_results(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $correlation = (string) Str::uuid();

        $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/auto-submit', [
            'integrity_event_id' => (string) Str::uuid(),
            'correlation_id' => $correlation,
            'trigger' => 'tab_hidden',
        ])->assertOk();

        $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/auto-submit', [
            'integrity_event_id' => (string) Str::uuid(),
            'correlation_id' => $correlation,
            'trigger' => 'window_blur',
        ])->assertOk();

        $this->assertSame(1, CbtResult::query()->where('attempt_id', $attempt->id)->count());
        $this->assertGreaterThanOrEqual(1, CbtExamIntegrityEvent::query()
            ->where('attempt_id', $attempt->id)
            ->where('event_type', CbtIntegrityEventType::TabHidden)
            ->count());
        $this->assertGreaterThanOrEqual(1, CbtExamIntegrityEvent::query()
            ->where('attempt_id', $attempt->id)
            ->where('correlation_id', $correlation)
            ->count());
    }

    public function test_manual_and_timer_submission_reasons_still_work(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $option = $ctx['examQuestion']->options()->firstOrFail();

        $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/answers', [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $option->id,
        ])->assertOk();

        $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/submit', [
            'reason' => CbtSubmissionReason::StudentManual->value,
        ])->assertOk();

        $this->assertSame(CbtSubmissionReason::StudentManual->value, $attempt->fresh()->submission_reason);

        $ctx2 = $this->cbtPublishedExam();
        $owner2 = $ctx2['student']->user;
        $attempt2 = app(CbtAttemptService::class)->start($ctx2['exam'], $owner2, $ctx2['student']);
        $this->actingAsCbt($owner2)->postJson('/api/v1/cbt/attempts/'.$attempt2->id.'/submit', [
            'reason' => CbtSubmissionReason::TimerExpired->value,
        ])->assertOk();
        $this->assertSame(CbtSubmissionReason::TimerExpired->value, $attempt2->fresh()->submission_reason);
    }
}
