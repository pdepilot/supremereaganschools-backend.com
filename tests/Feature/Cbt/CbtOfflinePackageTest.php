<?php

namespace Tests\Feature\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\RoleSlug;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtOfflinePackageService;
use App\Services\Cbt\CbtSubmissionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesCbtContext;
use Tests\TestCase;

class CbtOfflinePackageTest extends TestCase
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

    public function test_owner_receives_student_safe_frozen_offline_package(): void
    {
        $ctx = $this->cbtPublishedExam();
        $user = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $user, $ctx['student']);

        $package = $this->actingAsCbt($user)
            ->getJson('/api/v1/cbt/attempts/'.$attempt->id.'/offline-package')
            ->assertOk()
            ->json('data');

        $this->assertSame(CbtOfflinePackageService::PACKAGE_VERSION, $package['package_version']);
        $this->assertSame($attempt->uuid, $package['attempt']['uuid']);
        $this->assertSame($attempt->id, $package['attempt']['id']);
        $this->assertNotEmpty($package['attempt']['ends_at']);
        $this->assertNotEmpty($package['attempt']['started_at']);
        $this->assertNotEmpty($package['attempt']['server_now']);
        $this->assertTrue($package['exam']['is_frozen']);
        $this->assertNotEmpty($package['exam']['questions']);

        $question = $package['exam']['questions'][0];
        $this->assertSame($ctx['examQuestion']->id, $question['id']);
        $this->assertNotEmpty($question['options']);
        $this->assertSame($ctx['examQuestion']->options->count(), count($question['options']));
        $this->assertArrayHasKey('id', $question['options'][0]);
        $this->assertArrayNotHasKey('is_correct', $question['options'][0]);

        $json = json_encode($package);
        $this->assertStringNotContainsString('"is_correct"', $json);
        $this->assertStringNotContainsString('"answer_key"', $json);
        $this->assertStringNotContainsString('"correct_option"', $json);
        $this->assertStringNotContainsString('"correct_option_id"', $json);
        $this->assertArrayNotHasKey('score', $package);
        $this->assertArrayNotHasKey('grade', $package);
        $this->assertArrayNotHasKey('percentage', $package);
        $this->assertFalse($package['security']['contains_answer_key']);
        $this->assertFalse($package['security']['contains_scores']);
    }

    public function test_package_deadline_is_authoritative_and_not_extended(): void
    {
        Carbon::setTestNow('2026-09-09 10:00:00');
        $ctx = $this->cbtPublishedExam(['duration_minutes' => 30]);
        $user = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $user, $ctx['student']);

        $package = $this->actingAsCbt($user)
            ->getJson('/api/v1/cbt/attempts/'.$attempt->id.'/offline-package')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            optional($attempt->ends_at)?->toIso8601String(),
            $package['attempt']['ends_at']
        );

        Carbon::setTestNow('2026-09-09 10:20:00');
        $again = $this->actingAsCbt($user)
            ->getJson('/api/v1/cbt/attempts/'.$attempt->id.'/offline-package')
            ->assertOk()
            ->json('data.attempt.ends_at');

        $this->assertSame(optional($attempt->fresh()->ends_at)?->toIso8601String(), $again);
        Carbon::setTestNow();
    }

    public function test_other_student_cannot_retrieve_package_and_invalid_attempt_rejected(): void
    {
        $ctx = $this->cbtPublishedExam();
        $owner = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $owner, $ctx['student']);

        $intruder = $this->userWithRole(RoleSlug::Student);
        $this->actingAsCbt($intruder)
            ->getJson('/api/v1/cbt/attempts/'.$attempt->id.'/offline-package')
            ->assertNotFound();

        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();
        app(CbtAnswerService::class)->save($attempt, $owner, [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ]);
        app(CbtSubmissionService::class)->submit($attempt, $owner);

        $this->actingAsCbt($owner)
            ->getJson('/api/v1/cbt/attempts/'.$attempt->id.'/offline-package')
            ->assertStatus(422);

        $this->assertSame(CbtAttemptStatus::Submitted, $attempt->fresh()->status);
    }

    public function test_online_autosave_submit_and_marking_still_work(): void
    {
        $ctx = $this->cbtPublishedExam();
        $user = $ctx['student']->user;
        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $user, $ctx['student']);
        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();

        $this->actingAsCbt($user)
            ->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/answers', [
                'exam_question_id' => $ctx['examQuestion']->id,
                'selected_exam_option_id' => $correct->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.exam_question_id', $ctx['examQuestion']->id);

        $result = $this->actingAsCbt($user)
            ->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/submit', [])
            ->assertOk()
            ->json('data');

        $this->assertTrue($result['result_available']);
        $this->assertNotNull($attempt->fresh()->result);
        $this->assertSame('2.00', (string) $attempt->fresh()->result->score);
        $this->assertSame('100.00', (string) $attempt->fresh()->result->percentage);
    }

    public function test_result_checker_endpoints_unchanged_by_offline_package(): void
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

        $this->actingAsCbt($user)
            ->getJson('/api/v1/cbt/results/'.$result->id.'/access')
            ->assertOk();

        $this->actingAsCbt($user)
            ->getJson('/api/v1/cbt/result-checkers/pricing')
            ->assertOk();
    }
}
