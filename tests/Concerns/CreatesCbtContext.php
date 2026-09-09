<?php

namespace Tests\Concerns;

use App\Enums\CbtQuestionDifficulty;
use App\Enums\CbtQuestionType;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtQuestion;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AuthenticationService;
use App\Services\Cbt\CbtExamAssignmentService;
use App\Services\Cbt\CbtExamPublishService;
use App\Services\Cbt\CbtExamService;
use App\Services\Cbt\CbtExamSnapshotService;
use App\Services\Cbt\CbtQuestionBankService;
use Illuminate\Support\Str;

trait CreatesCbtContext
{
    protected function actingAsCbt(User $user): static
    {
        return $this->actingAs($user)->withSession([
            AuthenticationService::CBT_DESK_SESSION_KEY => true,
        ]);
    }
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $options
     */
    protected function cbtBankQuestion(array $attributes = [], ?array $options = null): CbtQuestion
    {
        $class = $attributes['school_class'] ?? $this->schoolClass();
        $subject = $attributes['subject'] ?? $this->subject(['code' => 'CBT-'.random_int(1000, 9999)]);

        unset($attributes['school_class'], $attributes['subject']);

        $options ??= [
            ['label' => 'A', 'body' => 'Correct', 'is_correct' => true, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Wrong', 'is_correct' => false, 'sort_order' => 2],
        ];

        return app(CbtQuestionBankService::class)->create(array_merge([
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'topic' => 'Topic',
            'difficulty' => CbtQuestionDifficulty::Easy,
            'type' => CbtQuestionType::Mcq,
            'stem' => 'What is 2 + 2?',
            'marks' => 1,
            'is_active' => true,
        ], $attributes), $options);
    }

    /**
     * @param  array<string, mixed>  $examAttributes
     * @return array{
     *     exam: CbtExam,
     *     question: CbtQuestion,
     *     examQuestion: CbtExamQuestion,
     *     student: StudentProfile,
     *     offering: \App\Models\ClassSectionOffering
     * }
     */
    protected function cbtPublishedExam(array $examAttributes = [], bool $assignStudent = true): array
    {
        $session = $this->academicSession(['name' => 'CBT-'.random_int(10000, 99999)]);
        $term = $this->termFor($session);
        $level = $this->level(['slug' => 'cbt-'.random_int(10000, 99999), 'name' => 'CBT Level '.random_int(1000, 9999)]);
        $class = $this->schoolClass($level);
        $section = $this->section($class);
        $campus = $this->campus(['name' => 'Campus '.random_int(10000, 99999)]);
        $offering = $this->offering($section, $session, $campus);
        $subject = $this->subject([
            'name' => 'Subject '.random_int(10000, 99999),
            'code' => 'SUB-'.Str::upper(Str::random(6)),
        ]);
        $student = $this->student();
        $this->enroll($student, $offering);

        $question = $this->cbtBankQuestion([
            'school_class' => $class,
            'subject' => $subject,
            'stem' => 'Capital of Nigeria?',
            'marks' => 2,
        ], [
            ['label' => 'A', 'body' => 'Lagos', 'is_correct' => false, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Abuja', 'is_correct' => true, 'sort_order' => 2],
            ['label' => 'C', 'body' => 'Kano', 'is_correct' => false, 'sort_order' => 3],
        ]);

        $exam = app(CbtExamService::class)->createDraft(array_merge([
            'title' => 'Published CBT',
            'subject_id' => $subject->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'duration_minutes' => 30,
            'pass_mark' => 50,
            'max_attempts' => 2,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ], $examAttributes));

        $examQuestion = app(CbtExamSnapshotService::class)->attach($exam, $question);

        $assignments = app(CbtExamAssignmentService::class);
        $assignments->assignToOffering($exam, $offering->id);
        if ($assignStudent) {
            // offering assignment already covers enrolled student; keep both paths testable via flag
        }

        $exam = app(CbtExamPublishService::class)->publish($exam->fresh() ?? $exam);

        return [
            'exam' => $exam->fresh(['examQuestions.options', 'assignments']) ?? $exam,
            'question' => $question->fresh(['options']) ?? $question,
            'examQuestion' => $examQuestion->fresh(['options']) ?? $examQuestion,
            'student' => $student,
            'offering' => $offering,
        ];
    }
}
