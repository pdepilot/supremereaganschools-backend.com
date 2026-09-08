<?php

namespace App\Services\Cbt;

use App\Enums\CbtExamStatus;
use App\Enums\CbtQuestionType;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtExamQuestionOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CbtExamPublishService
{
    public function __construct(
        private readonly CbtQuestionBankService $questions,
        private readonly CbtExamSnapshotService $snapshots,
    ) {}

    public function publish(CbtExam $exam): CbtExam
    {
        return DB::transaction(function () use ($exam) {
            /** @var CbtExam $exam */
            $exam = CbtExam::query()->lockForUpdate()->findOrFail($exam->id);
            $exam->load(['examQuestions.options', 'assignments', 'classSectionOffering', 'term', 'academicSession']);

            $this->assertPublishable($exam);

            $now = now();

            foreach ($exam->examQuestions as $examQuestion) {
                $examQuestion->update([
                    'is_frozen' => true,
                    'frozen_at' => $now,
                ]);
            }

            $this->snapshots->recalculateExamTotals($exam);

            $exam->update([
                'status' => CbtExamStatus::Published,
                'published_at' => $now,
                'is_active' => true,
            ]);

            return $exam->fresh(['examQuestions.options', 'assignments']) ?? $exam;
        });
    }

    private function assertPublishable(CbtExam $exam): void
    {
        if ($exam->status !== CbtExamStatus::Draft) {
            throw ValidationException::withMessages([
                'exam' => 'Only draft exams can be published.',
            ]);
        }

        if ((int) $exam->duration_minutes <= 0) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'Exam duration must be greater than zero.',
            ]);
        }

        if ($exam->classSectionOffering === null
            || $exam->term === null
            || $exam->academicSession === null
            || $exam->subject_id === null
        ) {
            throw ValidationException::withMessages([
                'exam' => 'Exam academic scope (subject, offering, session, term) is incomplete.',
            ]);
        }

        if ((int) $exam->classSectionOffering->academic_session_id !== (int) $exam->academic_session_id) {
            throw ValidationException::withMessages([
                'academic_session_id' => 'Academic session must match the class section offering.',
            ]);
        }

        if ((int) $exam->term->academic_session_id !== (int) $exam->academic_session_id) {
            throw ValidationException::withMessages([
                'term_id' => 'Term must belong to the exam academic session.',
            ]);
        }

        if ($exam->examQuestions->isEmpty()) {
            throw ValidationException::withMessages([
                'exam_questions' => 'Publish requires at least one exam question.',
            ]);
        }

        if ($exam->assignments->isEmpty()) {
            throw ValidationException::withMessages([
                'assignments' => 'Publish requires at least one exam assignment (class or student).',
            ]);
        }

        foreach ($exam->assignments as $assignment) {
            $hasOffering = $assignment->class_section_offering_id !== null;
            $hasStudent = $assignment->student_profile_id !== null;
            if ($hasOffering === $hasStudent) {
                throw ValidationException::withMessages([
                    'assignments' => 'Each assignment must target exactly one of class offering or student.',
                ]);
            }
        }

        $marksTotal = 0.0;

        foreach ($exam->examQuestions as $examQuestion) {
            $this->assertExamQuestionSnapshotValid($examQuestion);
            $marksTotal += (float) $examQuestion->marks;
        }

        $marksTotal = round($marksTotal, 2);
        $configuredMax = round((float) $exam->max_score, 2);
        if ($configuredMax > 0 && abs($configuredMax - $marksTotal) > 0.009) {
            // Allow auto-heal via recalculate; treat mismatch as recalculable unless wildly wrong after refresh
            $exam->max_score = $marksTotal;
        }

        if ($marksTotal <= 0) {
            throw ValidationException::withMessages([
                'max_score' => 'Published exams must have a positive total marks.',
            ]);
        }
    }

    private function assertExamQuestionSnapshotValid(CbtExamQuestion $examQuestion): void
    {
        if (! filled($examQuestion->stem)) {
            throw ValidationException::withMessages([
                'exam_questions' => "Exam question #{$examQuestion->id} is missing snapshot stem.",
            ]);
        }

        if ((float) $examQuestion->marks <= 0) {
            throw ValidationException::withMessages([
                'exam_questions' => "Exam question #{$examQuestion->id} must have positive marks.",
            ]);
        }

        $type = $examQuestion->type instanceof CbtQuestionType
            ? $examQuestion->type
            : CbtQuestionType::from((string) $examQuestion->type);

        if ($type === CbtQuestionType::Mcq) {
            $options = $examQuestion->options->map(fn (CbtExamQuestionOption $option) => [
                'body' => $option->body,
                'is_correct' => $option->is_correct,
            ])->all();

            $this->questions->assertMcqOptions($type, $options);
        }
    }
}
