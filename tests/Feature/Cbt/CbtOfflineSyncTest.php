<?php

namespace Tests\Feature\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\CbtSyncLogStatus;
use App\Enums\RoleSlug;
use App\Models\CbtAnswer;
use App\Models\CbtSyncLog;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtOfflineSyncService;
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

class CbtOfflineSyncTest extends TestCase
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

    public function test_auth_and_ownership_for_sync_endpoint(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $option = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();
        $payload = $this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $option->id);

        $this->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'protocol' => CbtOfflineSyncService::PROTOCOL,
            'events' => [$payload],
        ])->assertUnauthorized();

        $intruder = $this->userWithRole(RoleSlug::Student);
        $this->actingAsCbt($intruder)
            ->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
                'protocol' => CbtOfflineSyncService::PROTOCOL,
                'events' => [$payload],
            ])
            ->assertNotFound();

        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $this->actingAsCbt($officer)
            ->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
                'protocol' => CbtOfflineSyncService::PROTOCOL,
                'events' => [$payload],
            ])
            ->assertNotFound();

        $this->actingAsCbt($owner)
            ->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
                'protocol' => CbtOfflineSyncService::PROTOCOL,
                'events' => [$payload],
            ])
            ->assertOk()
            ->assertJsonPath('data.protocol', CbtOfflineSyncService::PROTOCOL)
            ->assertJsonPath('data.results.0.status', 'synced');
    }

    public function test_validation_rejects_foreign_question_and_option(): void
    {
        $ctx = $this->cbtPublishedExam();
        $other = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $foreignQuestion = $other['examQuestion'];
        $foreignOption = $foreignQuestion->options()->firstOrFail();
        $localOption = $ctx['examQuestion']->options()->firstOrFail();

        $badQuestion = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'events' => [$this->eventPayload($attempt->uuid, $foreignQuestion->id, $foreignOption->id)],
        ])->assertOk()->json('data.results.0');
        $this->assertSame('rejected_invalid_question', $badQuestion['status']);

        $badOption = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'events' => [$this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $foreignOption->id)],
        ])->assertOk()->json('data.results.0');
        $this->assertSame('rejected_invalid_option', $badOption['status']);

        $ok = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'events' => [$this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $localOption->id)],
        ])->assertOk()->json('data.results.0');
        $this->assertSame('synced', $ok['status']);
        $this->assertSame(1, CbtAnswer::query()->where('attempt_id', $attempt->id)->count());
    }

    public function test_idempotency_and_retry_do_not_duplicate_answers(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $option = $ctx['examQuestion']->options()->firstOrFail();
        $event = $this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $option->id);

        $first = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'events' => [$event],
        ])->assertOk()->json('data.results.0');
        $this->assertSame('synced', $first['status']);

        $second = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'events' => [$event],
        ])->assertOk()->json('data.results.0');
        $this->assertSame('already_synced', $second['status']);
        $this->assertSame(1, CbtAnswer::query()->where('attempt_id', $attempt->id)->count());
        $this->assertSame(1, CbtSyncLog::query()->where('event_id', $event['event_id'])->count());
        $this->assertSame(CbtSyncLogStatus::Accepted, CbtSyncLog::query()->where('event_id', $event['event_id'])->first()->status);
    }

    public function test_expiry_and_fake_client_timestamp_cannot_extend_deadline(): void
    {
        Carbon::setTestNow('2026-09-09 10:00:00');
        $ctx = $this->cbtPublishedExam(['duration_minutes' => 10]);
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $option = $ctx['examQuestion']->options()->firstOrFail();

        $accepted = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'events' => [$this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $option->id, [
                'client_answered_at' => '2026-09-09T10:05:00+00:00',
            ])],
        ])->assertOk()->json('data.results.0');
        $this->assertSame('synced', $accepted['status']);

        Carbon::setTestNow('2026-09-09 10:20:00');
        $rejected = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'events' => [$this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $option->id, [
                'client_answered_at' => '2026-09-09T10:05:00+00:00',
            ])],
        ])->assertOk()->json('data.results.0');
        $this->assertSame('rejected_expired', $rejected['status']);
        $this->assertTrue($attempt->fresh()->ends_at->equalTo(Carbon::parse('2026-09-09 10:10:00')));
        Carbon::setTestNow();
    }

    public function test_submitted_attempt_rejects_sync_without_reopening(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();
        app(CbtAnswerService::class)->save($attempt, $owner, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ]);
        app(CbtSubmissionService::class)->submit($attempt, $owner);

        $result = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'events' => [$this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $correct->id)],
        ])->assertOk()->json('data.results.0');

        $this->assertSame('rejected_closed', $result['status']);
        $this->assertSame(CbtAttemptStatus::Submitted, $attempt->fresh()->status);
    }

    public function test_ordering_latest_local_update_wins_and_stale_event_ignored(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $options = $ctx['examQuestion']->options()->orderBy('sort_order')->get();
        $first = $options[0];
        $second = $options[1];

        $older = $this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $first->id, [
            'local_sequence' => 1,
            'client_answered_at' => '2026-09-09T12:00:00+00:00',
        ]);
        $newer = $this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $second->id, [
            'local_sequence' => 2,
            'client_answered_at' => '2026-09-09T12:01:00+00:00',
        ]);

        $batch = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'events' => [$newer, $older],
        ])->assertOk()->json('data.results');

        $this->assertSame(2, count($batch));
        $answer = CbtAnswer::query()->where('attempt_id', $attempt->id)->firstOrFail();
        $this->assertSame($second->id, (int) $answer->selected_exam_option_id);

        $staleRetry = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'events' => [$this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $first->id, [
                'local_sequence' => 3,
                'client_answered_at' => '2026-09-09T11:59:00+00:00',
            ])],
        ])->assertOk()->json('data.results.0');
        $this->assertSame('already_synced', $staleRetry['status']);
        $this->assertSame($second->id, (int) $answer->fresh()->selected_exam_option_id);
    }

    public function test_batch_partial_success_and_security_payload(): void
    {
        $ctx = $this->cbtPublishedExam();
        $other = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $validOption = $ctx['examQuestion']->options()->firstOrFail();
        $foreign = $other['examQuestion'];

        $response = $this->actingAsCbt($owner)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/sync', [
            'batch_id' => (string) Str::uuid(),
            'events' => [
                $this->eventPayload($attempt->uuid, $ctx['examQuestion']->id, $validOption->id),
                $this->eventPayload($attempt->uuid, $foreign->id, $foreign->options()->firstOrFail()->id),
            ],
        ])->assertOk();

        $json = $response->getContent();
        $this->assertStringNotContainsString('"is_correct"', $json);
        $this->assertStringNotContainsString('"grade"', $json);
        $this->assertStringNotContainsString('"percentage"', $json);
        $this->assertStringNotContainsString('"passed"', $json);

        $results = collect($response->json('data.results'));
        $this->assertTrue($results->contains(fn ($row) => $row['status'] === 'synced'));
        $this->assertTrue($results->contains(fn ($row) => $row['status'] === 'rejected_invalid_question'));
        $this->assertSame(1, CbtAnswer::query()->where('attempt_id', $attempt->id)->count());
    }

    public function test_online_autosave_still_works_alongside_sync(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);
        $option = $ctx['examQuestion']->options()->firstOrFail();

        $this->actingAsCbt($owner)
            ->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/answers', [
                'exam_question_id' => $ctx['examQuestion']->id,
                'selected_exam_option_id' => $option->id,
                'client_answered_at' => now()->toIso8601String(),
            ])
            ->assertOk();

        $this->assertSame(1, CbtAnswer::query()->where('attempt_id', $attempt->id)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eventPayload(string $attemptUuid, int $examQuestionId, ?int $optionId, array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'type' => 'answer',
            'attempt_uuid' => $attemptUuid,
            'exam_question_id' => $examQuestionId,
            'selected_exam_option_id' => $optionId,
            'client_answered_at' => now()->toIso8601String(),
            'local_sequence' => 1,
        ], $overrides);
    }
}
