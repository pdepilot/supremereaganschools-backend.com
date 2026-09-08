<?php

namespace Tests\Feature\Cbt;

use App\Models\CbtExamQuestion;
use App\Services\Cbt\CbtExamPublishService;
use App\Services\Cbt\CbtExamService;
use App\Services\Cbt\CbtExamSnapshotService;
use App\Services\Cbt\CbtQuestionBankService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAcademicContext;
use Tests\Concerns\CreatesCbtContext;
use Tests\TestCase;

class CbtQuestionBankAndSnapshotTest extends TestCase
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

    public function test_question_bank_create_update_and_options(): void
    {
        $bank = app(CbtQuestionBankService::class);
        $question = $this->cbtBankQuestion(['stem' => '2+2?', 'marks' => 1]);

        $this->assertSame('2+2?', $question->stem);
        $this->assertCount(2, $question->options);
        $this->assertSame(1, $question->options->where('is_correct', true)->count());

        $updated = $bank->update($question, ['stem' => '3+3?'], [
            ['body' => '6', 'is_correct' => true, 'sort_order' => 1],
            ['body' => '9', 'is_correct' => false, 'sort_order' => 2],
            ['body' => '5', 'is_correct' => false, 'sort_order' => 3],
        ]);

        $this->assertSame('3+3?', $updated->stem);
        $this->assertCount(3, $updated->options);
        $this->assertTrue($bank->setActive($updated, false)->is_active === false);
    }

    public function test_invalid_mcq_configuration_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->cbtBankQuestion([], [
            ['body' => 'A', 'is_correct' => true],
            ['body' => 'B', 'is_correct' => true],
        ]);
    }

    public function test_attach_copies_snapshot_and_provenance(): void
    {
        $session = $this->academicSession(['name' => 'snap-'.random_int(1000, 9999)]);
        $term = $this->termFor($session);
        $level = $this->level(['slug' => 'snap-'.random_int(1000, 9999)]);
        $class = $this->schoolClass($level);
        $offering = $this->offering($this->section($class), $session);
        $subject = $this->subject(['code' => 'SNP'.random_int(100, 999)]);
        $question = $this->cbtBankQuestion(['school_class' => $class, 'subject' => $subject, 'stem' => 'Bank stem', 'marks' => 3]);

        $exam = app(CbtExamService::class)->createDraft([
            'title' => 'Draft',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 20,
        ]);

        $examQuestion = app(CbtExamSnapshotService::class)->attach($exam, $question);

        $this->assertSame('Bank stem', $examQuestion->stem);
        $this->assertSame(3.0, (float) $examQuestion->marks);
        $this->assertSame($question->id, $examQuestion->question_id);
        $this->assertCount(2, $examQuestion->options);
        $this->assertNotNull($examQuestion->options->first()->source_option_id);
        $this->assertTrue($examQuestion->options->contains(fn ($o) => $o->is_correct));
        $this->assertSame(1, (int) $exam->fresh()->question_count);
        $this->assertSame(3.0, (float) $exam->fresh()->max_score);
    }

    public function test_bank_edits_do_not_mutate_snapshot_until_explicit_refresh(): void
    {
        $session = $this->academicSession(['name' => 'rf-'.random_int(1000, 9999)]);
        $term = $this->termFor($session);
        $level = $this->level(['slug' => 'rf-'.random_int(1000, 9999)]);
        $class = $this->schoolClass($level);
        $offering = $this->offering($this->section($class), $session);
        $subject = $this->subject(['code' => 'RF'.random_int(100, 999)]);
        $question = $this->cbtBankQuestion(['school_class' => $class, 'subject' => $subject, 'stem' => 'Original']);

        $exam = app(CbtExamService::class)->createDraft([
            'title' => 'Draft',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 20,
        ]);

        $snapshots = app(CbtExamSnapshotService::class);
        $examQuestion = $snapshots->attach($exam, $question);

        app(CbtQuestionBankService::class)->update($question, ['stem' => 'Changed in bank'], [
            ['body' => 'New correct', 'is_correct' => true, 'sort_order' => 1],
            ['body' => 'New wrong', 'is_correct' => false, 'sort_order' => 2],
        ]);

        $examQuestion->refresh();
        $this->assertSame('Original', $examQuestion->stem);
        $this->assertNotSame('New correct', $examQuestion->options()->orderBy('sort_order')->value('body'));

        $refreshed = $snapshots->refreshFromBank($examQuestion);
        $this->assertSame('Changed in bank', $refreshed->stem);
        $this->assertSame('New correct', $refreshed->options()->where('is_correct', true)->value('body'));
    }

    public function test_student_safe_payload_hides_correct_flags(): void
    {
        $question = $this->cbtBankQuestion();
        $safe = app(CbtQuestionBankService::class)->studentSafeBankOptions($question);

        $this->assertArrayNotHasKey('is_correct', $safe[0]);
    }

    public function test_publish_freezes_snapshot_and_blocks_mutation(): void
    {
        $ctx = $this->cbtPublishedExam();
        $examQuestion = CbtExamQuestion::query()->findOrFail($ctx['examQuestion']->id);

        $this->assertTrue($examQuestion->is_frozen);
        $this->assertNotNull($examQuestion->frozen_at);

        $snapshots = app(CbtExamSnapshotService::class);

        try {
            $snapshots->updateSnapshotMarks($examQuestion, 9);
            $this->fail('Expected frozen marks update to fail');
        } catch (ValidationException) {
            // expected
        }

        try {
            $snapshots->refreshFromBank($examQuestion);
            $this->fail('Expected frozen refresh to fail');
        } catch (ValidationException) {
            // expected
        }

        try {
            $snapshots->reorder($ctx['exam'], [$examQuestion->id]);
            $this->fail('Expected frozen reorder to fail');
        } catch (ValidationException) {
            // expected
        }

        $frozenStem = $examQuestion->stem;
        app(CbtQuestionBankService::class)->update($ctx['question'], ['stem' => 'Bank mutated after publish']);
        $this->assertSame($frozenStem, $examQuestion->fresh()->stem);
    }

    public function test_cannot_republish_non_draft(): void
    {
        $ctx = $this->cbtPublishedExam();

        $this->expectException(ValidationException::class);
        app(CbtExamPublishService::class)->publish($ctx['exam']);
    }
}
