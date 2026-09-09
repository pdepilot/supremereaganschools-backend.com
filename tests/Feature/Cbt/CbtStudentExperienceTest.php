<?php

namespace Tests\Feature\Cbt;

use App\Enums\CbtExamStatus;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtSubmissionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesCbtContext;
use Tests\TestCase;

class CbtStudentExperienceTest extends TestCase
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

    public function test_student_can_open_desk_exam_attempt_and_results_pages(): void
    {
        $ctx = $this->cbtPublishedExam();
        $user = $ctx['student']->user;

        $this->actingAsCbt($user)->get('/cbt')->assertOk()->assertSee('CBT Student Desk', false);
        $this->actingAsCbt($user)->get('/cbt/exams/'.$ctx['exam']->id)->assertOk()->assertSee('Exam instructions', false);

        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $user, $ctx['student']);
        $this->actingAsCbt($user)->get('/cbt/attempts/'.$attempt->id)->assertOk()->assertSee('Submit exam', false);
        $this->actingAsCbt($user)->get('/cbt/results')->assertOk()->assertSee('CBT results', false);
    }

    public function test_desk_api_lists_only_eligible_exams_with_attempt_metadata(): void
    {
        $ctx = $this->cbtPublishedExam();
        $other = $this->cbtPublishedExam();

        $response = $this->actingAsCbt($ctx['student']->user)
            ->getJson('/api/v1/cbt/exams')
            ->assertOk();

        $ids = collect($response->json('data.exams'))->pluck('id');
        $this->assertTrue($ids->contains($ctx['exam']->id));
        $this->assertFalse($ids->contains($other['exam']->id));

        $mine = collect($response->json('data.exams'))->firstWhere('id', $ctx['exam']->id);
        $this->assertSame('not_started', $mine['attempt_status']);
        $this->assertSame(2, $mine['attempts_remaining']);
        $this->assertArrayHasKey('name', $response->json('data.student'));
    }

    public function test_exam_payload_and_attempt_resume_exclude_answer_keys(): void
    {
        $ctx = $this->cbtPublishedExam();
        $user = $ctx['student']->user;

        $examPayload = $this->actingAsCbt($user)
            ->getJson('/api/v1/cbt/exams/'.$ctx['exam']->id)
            ->assertOk()
            ->json('data');

        $this->assertTrue($examPayload['can_start']);
        $this->assertStringNotContainsString('is_correct', json_encode($examPayload));

        $attempt = $this->actingAsCbt($user)
            ->postJson('/api/v1/cbt/exams/'.$ctx['exam']->id.'/attempts')
            ->assertCreated()
            ->json('data');

        $resume = $this->actingAsCbt($user)
            ->getJson('/api/v1/cbt/attempts/'.$attempt['id'])
            ->assertOk()
            ->json('data');

        $this->assertSame($attempt['id'], $resume['id']);
        $this->assertNotNull($resume['ends_at']);
        $this->assertArrayHasKey('questions', $resume['exam']);
        $this->assertStringNotContainsString('is_correct', json_encode($resume));

        $again = $this->actingAsCbt($user)
            ->postJson('/api/v1/cbt/exams/'.$ctx['exam']->id.'/attempts')
            ->assertStatus(422);
        $this->assertArrayHasKey('attempt', $again->json('errors'));
    }

    public function test_full_online_answer_submit_and_results_history(): void
    {
        $ctx = $this->cbtPublishedExam();
        $user = $ctx['student']->user;

        $attemptId = $this->actingAsCbt($user)
            ->postJson('/api/v1/cbt/exams/'.$ctx['exam']->id.'/attempts')
            ->assertCreated()
            ->json('data.id');

        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();

        $this->actingAsCbt($user)->postJson('/api/v1/cbt/attempts/'.$attemptId.'/answers', [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ])->assertOk();

        $this->actingAsCbt($user)->postJson('/api/v1/cbt/attempts/'.$attemptId.'/answers', [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ])->assertOk();

        $result = $this->actingAsCbt($user)
            ->postJson('/api/v1/cbt/attempts/'.$attemptId.'/submit')
            ->assertOk()
            ->json('data');

        $this->assertTrue($result['result_available'] ?? true);
        $this->assertFalse($result['details_unlocked']);
        $this->assertNull($result['percentage']);
        $this->assertArrayHasKey('exam_title', $result);

        $history = $this->actingAsCbt($user)->getJson('/api/v1/cbt/results')->assertOk()->json('data.results');
        $this->assertCount(1, $history);
        $this->assertSame($result['id'], $history[0]['id']);
        $this->assertFalse($history[0]['details_unlocked']);
        $this->assertNull($history[0]['score']);

        $this->settings(['cbt_result_details_require_payment' => false]);
        $unlocked = $this->actingAsCbt($user)->getJson('/api/v1/cbt/results/'.$result['id'].'/detailed')->assertOk()->json('data.result');
        $this->assertSame('100.00', $unlocked['percentage']);

        $this->actingAsCbt($user)->getJson('/api/v1/cbt/attempts/'.$attemptId)
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_expired_attempt_rejects_answers_and_unpublished_cannot_be_viewed(): void
    {
        Carbon::setTestNow('2026-09-07 12:00:00');

        try {
            $ctx = $this->cbtPublishedExam([
                'duration_minutes' => 1,
                'starts_at' => Carbon::parse('2026-09-07 11:00:00'),
                'ends_at' => Carbon::parse('2026-09-07 18:00:00'),
            ]);
            $user = $ctx['student']->user;
            $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $user, $ctx['student']);

            Carbon::setTestNow('2026-09-07 12:05:00');
            $option = $ctx['examQuestion']->options()->firstOrFail();

            $this->actingAsCbt($user)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/answers', [
                'exam_question_id' => $ctx['examQuestion']->id,
                'selected_exam_option_id' => $option->id,
            ])->assertStatus(422);

            $ctx['exam']->update([
                'status' => CbtExamStatus::Draft,
                'published_at' => null,
            ]);

            $this->actingAsCbt($user)
                ->getJson('/api/v1/cbt/exams/'.$ctx['exam']->id)
                ->assertNotFound();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_another_student_cannot_open_attempt_page_or_results(): void
    {
        $ctx = $this->cbtPublishedExam();
        $other = $this->cbtPublishedExam();

        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $ctx['student']->user, $ctx['student']);
        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();
        app(CbtAnswerService::class)->save($attempt, $ctx['student']->user, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ]);
        app(CbtSubmissionService::class)->submit($attempt, $ctx['student']->user);

        $this->actingAsCbt($other['student']->user)
            ->get('/cbt/attempts/'.$attempt->id)
            ->assertNotFound();

        $this->actingAsCbt($other['student']->user)
            ->getJson('/api/v1/cbt/results')
            ->assertOk()
            ->assertJsonCount(0, 'data.results');
    }
}
