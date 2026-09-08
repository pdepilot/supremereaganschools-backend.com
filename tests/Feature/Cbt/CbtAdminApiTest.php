<?php

namespace Tests\Feature\Cbt;

use App\Enums\CbtExamStatus;
use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Services\Cbt\CbtQuestionBankService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesCbtContext;
use Tests\TestCase;

class CbtAdminApiTest extends TestCase
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

    public function test_manager_can_create_and_edit_question_bank(): void
    {
        $manager = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $class = $this->schoolClass();
        $subject = $this->subject(['code' => 'ADM'.random_int(100, 999)]);

        $create = $this->actingAs($manager)->postJson('/api/v1/cbt/admin/questions', [
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'topic' => 'Algebra',
            'difficulty' => 'easy',
            'type' => 'mcq',
            'stem' => 'What is 5 + 5?',
            'marks' => 2,
            'is_active' => true,
            'options' => [
                ['label' => 'A', 'body' => '10', 'is_correct' => true],
                ['label' => 'B', 'body' => '11', 'is_correct' => false],
            ],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.stem', 'What is 5 + 5?')
            ->assertJsonPath('data.options.0.is_correct', true);

        $id = $create->json('data.id');

        $this->actingAs($manager)->putJson('/api/v1/cbt/admin/questions/'.$id, [
            'stem' => 'What is 6 + 6?',
            'options' => [
                ['label' => 'A', 'body' => '12', 'is_correct' => true],
                ['label' => 'B', 'body' => '13', 'is_correct' => false],
                ['label' => 'C', 'body' => '11', 'is_correct' => false],
            ],
        ])->assertOk()->assertJsonPath('data.stem', 'What is 6 + 6?');

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/questions/'.$id.'/active', [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($manager)->getJson('/api/v1/cbt/admin/questions/'.$id)
            ->assertOk()
            ->assertJsonPath('data.options.0.is_correct', true);
    }

    public function test_unauthorized_user_cannot_manage_questions_or_exams(): void
    {
        $student = $this->student();
        $content = $this->userWithRole(RoleSlug::ContentManager);

        $this->actingAs($student->user)->postJson('/api/v1/cbt/admin/questions', [])
            ->assertForbidden();
        $this->actingAs($content)->getJson('/api/v1/cbt/admin/questions')
            ->assertForbidden();
        $this->actingAs($content)->postJson('/api/v1/cbt/admin/exams', [])
            ->assertForbidden();
    }

    public function test_mcq_validation_rejects_invalid_options(): void
    {
        $manager = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $class = $this->schoolClass();
        $subject = $this->subject(['code' => 'BAD'.random_int(100, 999)]);

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/questions', [
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'stem' => 'Broken?',
            'marks' => 1,
            'options' => [
                ['body' => 'A', 'is_correct' => true],
                ['body' => 'B', 'is_correct' => true],
            ],
        ])->assertStatus(422);

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/questions', [
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'stem' => 'No options',
            'marks' => 1,
            'options' => [],
        ])->assertStatus(422);
    }

    public function test_draft_exam_attach_reorder_refresh_and_publish_freeze(): void
    {
        $manager = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $session = $this->academicSession(['name' => 'ADM-'.random_int(10000, 99999)]);
        $term = $this->termFor($session);
        $level = $this->level(['slug' => 'adm-'.random_int(10000, 99999)]);
        $class = $this->schoolClass($level);
        $offering = $this->offering($this->section($class), $session);
        $subject = $this->subject(['code' => 'EX'.random_int(100, 999)]);
        $q1 = $this->cbtBankQuestion(['school_class' => $class, 'subject' => $subject, 'stem' => 'Q1', 'marks' => 2]);
        $q2 = $this->cbtBankQuestion(['school_class' => $class, 'subject' => $subject, 'stem' => 'Q2', 'marks' => 3]);

        $create = $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams', [
            'title' => 'Draft Admin Exam',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 45,
            'pass_mark' => 50,
            'write_to_assessment_score' => false,
        ])->assertCreated();

        $this->assertFalse((bool) $create->json('data.write_to_assessment_score'));
        $examId = $create->json('data.id');

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/questions', [
            'question_id' => $q1->id,
        ])->assertOk();

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/questions', [
            'question_id' => $q2->id,
        ])->assertOk();

        $exam = CbtExam::query()->with('examQuestions')->findOrFail($examId);
        $ids = $exam->examQuestions->pluck('id')->all();
        $this->assertCount(2, $ids);

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/reorder', [
            'exam_question_ids' => array_reverse($ids),
        ])->assertOk();

        app(CbtQuestionBankService::class)->update($q1, ['stem' => 'Q1 changed'], null);
        $eq1 = CbtExamQuestion::query()->where('exam_id', $examId)->where('question_id', $q1->id)->firstOrFail();
        $this->assertSame('Q1', $eq1->stem);

        $refreshed = $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/questions/'.$eq1->id.'/refresh')
            ->assertOk()
            ->json('data.exam_questions');

        $this->assertTrue(collect($refreshed)->contains(fn (array $row) => $row['stem'] === 'Q1 changed'));
        $this->assertSame('Q1 changed', $eq1->fresh()->stem);

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/assignments', [
            'type' => 'offering',
            'class_section_offering_id' => $offering->id,
        ])->assertOk();

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', CbtExamStatus::Published->value)
            ->assertJsonPath('data.is_frozen', true);

        $published = CbtExam::query()->with('examQuestions')->findOrFail($examId);
        $this->assertNotNull($published->published_at);
        $this->assertTrue($published->examQuestions->every(fn ($row) => $row->is_frozen && $row->frozen_at !== null));

        $this->actingAs($manager)->putJson('/api/v1/cbt/admin/exams/'.$examId, [
            'title' => 'Should fail',
        ])->assertStatus(422);

        $frozenEq = $published->examQuestions->first();
        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/questions/'.$frozenEq->id.'/refresh')
            ->assertStatus(422);

        $this->actingAs($manager)->deleteJson('/api/v1/cbt/admin/exams/'.$examId.'/questions/'.$frozenEq->id)
            ->assertStatus(422);

        app(CbtQuestionBankService::class)->update($q1, ['stem' => 'Bank after publish'], null);
        $frozenQ1 = $published->examQuestions->firstWhere('question_id', $q1->id);
        $this->assertSame('Q1 changed', $frozenQ1->fresh()->stem);
    }

    public function test_invalid_exam_cannot_publish_and_empty_draft_rejected(): void
    {
        $manager = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $session = $this->academicSession(['name' => 'PUB-'.random_int(10000, 99999)]);
        $term = $this->termFor($session);
        $offering = $this->offering($this->section($this->schoolClass()), $session);
        $subject = $this->subject(['code' => 'NP'.random_int(100, 999)]);

        $examId = $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams', [
            'title' => 'Not ready',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 20,
        ])->assertCreated()->json('data.id');

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/publish')
            ->assertStatus(422);
    }

    public function test_assignments_class_and_individual_with_duplicate_prevention(): void
    {
        $manager = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $session = $this->academicSession(['name' => 'ASN-'.random_int(10000, 99999)]);
        $term = $this->termFor($session);
        $class = $this->schoolClass();
        $offering = $this->offering($this->section($class), $session);
        $subject = $this->subject(['code' => 'AS'.random_int(100, 999)]);
        $student = $this->student();
        $question = $this->cbtBankQuestion(['school_class' => $class, 'subject' => $subject]);

        $examId = $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams', [
            'title' => 'Assign me',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 15,
        ])->json('data.id');

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/questions', [
            'question_id' => $question->id,
        ])->assertOk();

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/assignments', [
            'type' => 'offering',
            'class_section_offering_id' => $offering->id,
        ])->assertOk();

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/assignments', [
            'type' => 'offering',
            'class_section_offering_id' => $offering->id,
        ])->assertStatus(422);

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/assignments', [
            'type' => 'student',
            'student_profile_id' => $student->id,
        ])->assertOk();

        $this->actingAs($manager)->postJson('/api/v1/cbt/admin/exams/'.$examId.'/assignments', [
            'type' => 'student',
            'student_profile_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_admin_preview_shows_keys_student_endpoint_does_not(): void
    {
        $ctx = $this->cbtPublishedExam();
        $manager = $this->userWithRole(RoleSlug::ExaminationOfficer);

        $preview = $this->actingAs($manager)->getJson('/api/v1/cbt/admin/exams/'.$ctx['exam']->id.'/preview')
            ->assertOk();

        $this->assertSame('ADMIN PREVIEW', $preview->json('data.label'));
        $options = collect($preview->json('data.exam_questions.0.options'));
        $this->assertTrue($options->contains(fn ($row) => array_key_exists('is_correct', $row) && $row['is_correct'] === true));

        $studentView = $this->actingAs($ctx['student']->user)
            ->getJson('/api/v1/cbt/exams/'.$ctx['exam']->id)
            ->assertOk();

        $this->assertStringNotContainsString('is_correct', (string) json_encode($studentView->json('data')));
    }

    public function test_admin_results_require_mark_or_manage_and_students_stay_isolated(): void
    {
        $ctx = $this->cbtPublishedExam();
        $user = $ctx['student']->user;

        $attemptId = $this->actingAs($user)
            ->postJson('/api/v1/cbt/exams/'.$ctx['exam']->id.'/attempts')
            ->assertCreated()
            ->json('data.id');

        $correct = $ctx['examQuestion']->options()->where('is_correct', true)->firstOrFail();

        $this->actingAs($user)->postJson('/api/v1/cbt/attempts/'.$attemptId.'/answers', [
            'exam_question_id' => $ctx['examQuestion']->id,
            'selected_exam_option_id' => $correct->id,
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/v1/cbt/attempts/'.$attemptId.'/submit')->assertOk();

        $manager = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $this->actingAs($manager)->getJson('/api/v1/cbt/admin/results')
            ->assertOk()
            ->assertJsonPath('data.items.0.student_name', $ctx['student']->fullName());

        $marker = $this->userWithRole(RoleSlug::Teacher, [
            'email' => 'marker'.random_int(1000, 9999).'@example.test',
        ]);
        $marker->roles->first()->permissions()->sync(
            \App\Models\Permission::query()
                ->whereIn('slug', [PermissionSlug::CbtView->value, PermissionSlug::CbtMark->value])
                ->pluck('id')
        );
        $marker->unsetRelation('roles');

        $this->actingAs($marker->fresh())->getJson('/api/v1/cbt/admin/results')->assertOk();
        $this->actingAs($marker->fresh())->getJson('/api/v1/cbt/admin/questions')->assertForbidden();

        $outsider = $this->student();
        $this->actingAs($outsider->user)->getJson('/api/v1/cbt/admin/results')->assertForbidden();

        $mine = $this->actingAs($user)->getJson('/api/v1/cbt/results')->assertOk()->json('data.results');
        $this->assertCount(1, $mine);

        $theirs = $this->actingAs($outsider->user)->getJson('/api/v1/cbt/results')->assertOk()->json('data.results');
        $this->assertCount(0, $theirs);
    }

    public function test_admin_pages_require_cbt_staff_permissions(): void
    {
        $manager = $this->userWithRole(RoleSlug::ExaminationOfficer);
        $this->actingAs($manager)->get('/cbt/admin')->assertOk()->assertSee('CBT Administration', false);
        $this->actingAs($manager)->get('/cbt/admin/questions')->assertOk()->assertSee('Question bank', false);

        $student = $this->student();
        $this->actingAs($student->user)->get('/cbt/admin/questions')->assertForbidden();
    }
}
