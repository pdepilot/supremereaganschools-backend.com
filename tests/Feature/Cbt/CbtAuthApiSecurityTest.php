<?php

namespace Tests\Feature\Cbt;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\CbtAttempt;
use App\Models\User;
use App\Services\Cbt\CbtAnswerService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtSubmissionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesCbtContext;
use Tests\TestCase;

class CbtAuthApiSecurityTest extends TestCase
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

    public function test_cbt_login_page_is_available(): void
    {
        $this->get('/cbt/login')->assertOk();
    }

    public function test_student_cbt_login_reuses_existing_user(): void
    {
        $ctx = $this->cbtPublishedExam();
        $student = $ctx['student'];
        $student->user->forceFill(['password' => Hash::make('SecretPass1!')])->save();
        $before = User::query()->count();

        $response = $this->postJson('/cbt/login', [
            'portal' => 'cbt',
            'admission_number' => $student->admission_number,
            'password' => 'SecretPass1!',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertAuthenticatedAs($student->user);
        $this->assertSame($before, User::query()->count());
        $response->assertJsonPath('data.redirect', '/cbt');
    }

    public function test_examination_officer_cbt_login_goes_to_admin(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer, [
            'email' => 'exam.cbt@example.test',
            'password' => Hash::make('SecretPass1!'),
        ]);

        // Portal password must not work on CBT staff login.
        $this->postJson('/cbt/login', [
            'portal' => 'cbt',
            'email' => 'exam.cbt@example.test',
            'password' => 'SecretPass1!',
        ])->assertStatus(422);

        $this->configureCbtDeskLogin('cbt.desk@example.test', 'CbtDeskPass1!', $officer);

        $response = $this->postJson('/cbt/login', [
            'portal' => 'cbt',
            'email' => 'cbt.desk@example.test',
            'password' => 'CbtDeskPass1!',
        ]);

        $response->assertOk();
        $this->assertAuthenticatedAs($officer);
        $response->assertJsonPath('data.redirect', '/cbt/admin');
    }

    public function test_invalid_credentials_fail_and_portal_admin_without_cbt_permission_cannot_enter(): void
    {
        $this->postJson('/cbt/login', [
            'portal' => 'cbt',
            'email' => 'nobody@example.test',
            'password' => 'wrong',
        ])->assertStatus(422);

        $content = $this->userWithRole(RoleSlug::ContentManager, [
            'email' => 'content@example.test',
            'password' => Hash::make('SecretPass1!'),
        ]);

        $this->postJson('/cbt/login', [
            'portal' => 'cbt',
            'email' => 'content@example.test',
            'password' => 'SecretPass1!',
        ])->assertStatus(422);

        $this->actingAs($content)->getJson('/cbt')->assertForbidden();
        $this->actingAs($content)->getJson('/api/v1/cbt/admin')->assertForbidden();
    }

    public function test_student_cannot_access_admin_or_other_student_attempt(): void
    {
        $ctx = $this->cbtPublishedExam();
        $other = $this->cbtPublishedExam();

        $attempt = app(CbtAttemptService::class)->start(
            $ctx['exam'],
            $ctx['student']->user,
            $ctx['student'],
        );

        $this->actingAs($other['student']->user)
            ->get('/cbt/admin')
            ->assertForbidden();

        $this->actingAs($other['student']->user)
            ->getJson('/api/v1/cbt/admin')
            ->assertForbidden();

        $this->actingAs($other['student']->user)
            ->getJson('/api/v1/cbt/attempts/'.$attempt->id)
            ->assertNotFound();

        $this->actingAs($other['student']->user)
            ->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/submit')
            ->assertNotFound();
    }

    public function test_eligible_student_exam_package_excludes_answer_keys(): void
    {
        $ctx = $this->cbtPublishedExam();

        $response = $this->actingAs($ctx['student']->user)
            ->getJson('/api/v1/cbt/exams/'.$ctx['exam']->id);

        $response->assertOk();
        $payload = $response->json('data');
        $this->assertArrayHasKey('questions', $payload);
        $this->assertNotEmpty($payload['questions']);
        $json = json_encode($payload);
        $this->assertStringNotContainsString('is_correct', $json);
        $this->assertArrayNotHasKey('is_correct', $payload['questions'][0]['options'][0]);
    }

    public function test_ineligible_and_unpublished_exam_are_blocked(): void
    {
        $ctx = $this->cbtPublishedExam();
        $outsider = $this->student();

        $this->actingAs($outsider->user)
            ->getJson('/api/v1/cbt/exams/'.$ctx['exam']->id)
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->postJson('/api/v1/cbt/exams/'.$ctx['exam']->id.'/attempts')
            ->assertForbidden();

        $draft = $this->cbtPublishedExam();
        $draft['exam']->update(['status' => \App\Enums\CbtExamStatus::Draft, 'published_at' => null]);

        $this->actingAs($draft['student']->user)
            ->getJson('/api/v1/cbt/exams/'.$draft['exam']->id)
            ->assertNotFound();
    }

    public function test_attempt_answer_submit_flow_and_idempotency(): void
    {
        $ctx = $this->cbtPublishedExam();
        $user = $ctx['student']->user;

        $start = $this->actingAs($user)
            ->postJson('/api/v1/cbt/exams/'.$ctx['exam']->id.'/attempts', [])
            ->assertCreated();

        $attemptId = $start->json('data.id');
        $this->assertNotNull($start->json('data.started_at'));
        $this->assertNotNull($start->json('data.ends_at'));
        $this->assertNotNull($start->json('data.server_now'));

        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();

        $this->actingAs($user)->postJson('/api/v1/cbt/attempts/'.$attemptId.'/answers', [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
            'score' => 999,
            'is_correct' => true,
        ])->assertOk();

        $submit = $this->actingAs($user)
            ->postJson('/api/v1/cbt/attempts/'.$attemptId.'/submit', [
                'score' => 0,
                'percentage' => 0,
            ])
            ->assertOk();

        $resultId = $submit->json('data.id');
        $this->assertSame('100.00', $submit->json('data.percentage'));

        $again = $this->actingAs($user)
            ->postJson('/api/v1/cbt/attempts/'.$attemptId.'/submit')
            ->assertOk();

        $this->assertSame($resultId, $again->json('data.id'));
        $this->assertSame(1, CbtAttempt::query()->whereKey($attemptId)->count());
    }

    public function test_invalid_option_and_foreign_option_rejected(): void
    {
        $ctx = $this->cbtPublishedExam();
        $other = $this->cbtPublishedExam();
        $user = $ctx['student']->user;

        $attempt = app(CbtAttemptService::class)->start($ctx['exam'], $user, $ctx['student']);
        $foreign = $other['examQuestion']->options()->firstOrFail();

        $this->actingAs($user)->postJson('/api/v1/cbt/attempts/'.$attempt->id.'/answers', [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $foreign->id,
        ])->assertStatus(422);
    }

    public function test_student_results_are_isolated(): void
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

        $mine = $this->actingAs($ctx['student']->user)->getJson('/api/v1/cbt/results')->assertOk();
        $this->assertCount(1, $mine->json('data.results'));

        $theirs = $this->actingAs($other['student']->user)->getJson('/api/v1/cbt/results')->assertOk();
        $this->assertCount(0, $theirs->json('data.results'));
    }

    public function test_authorized_manager_can_open_admin_entry(): void
    {
        $officer = $this->userWithRole(RoleSlug::ExaminationOfficer);

        $this->actingAs($officer)->get('/cbt/admin')->assertOk();
        $this->actingAs($officer)->getJson('/api/v1/cbt/admin')
            ->assertOk()
            ->assertJsonPath('data.capabilities.manage', true);

        $proctor = $this->userWithRole(RoleSlug::Teacher, [
            'email' => 'proctor@example.test',
        ]);
        $proctor->roles->first()->permissions()->sync(
            \App\Models\Permission::query()
                ->whereIn('slug', [PermissionSlug::CbtView->value, PermissionSlug::CbtProctor->value])
                ->pluck('id')
        );
        $proctor->unsetRelation('roles');

        $this->actingAs($proctor->fresh())->getJson('/api/v1/cbt/admin')
            ->assertOk()
            ->assertJsonPath('data.capabilities.proctor', true)
            ->assertJsonPath('data.capabilities.manage', false);
    }
}
