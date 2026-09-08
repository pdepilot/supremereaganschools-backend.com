<?php

namespace App\Services\Cbt;

use App\Enums\CbtQuestionType;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtExamQuestionOption;
use App\Models\CbtQuestion;
use App\Models\CbtQuestionOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CbtExamSnapshotService
{
    public function __construct(
        private readonly CbtExamConfigurationGuard $guard,
        private readonly CbtQuestionBankService $questions,
    ) {}

    public function attach(CbtExam $exam, CbtQuestion $question, ?int $sortOrder = null, ?float $marksOverride = null): CbtExamQuestion
    {
        $this->guard->assertExamEditable($exam);
        $this->questions->assertPersistedMcqValid($question);

        return DB::transaction(function () use ($exam, $question, $sortOrder, $marksOverride) {
            $existing = CbtExamQuestion::query()
                ->where('exam_id', $exam->id)
                ->where('question_id', $question->id)
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'question_id' => 'This question is already attached to the exam.',
                ]);
            }

            $order = $sortOrder ?? ((int) $exam->examQuestions()->max('sort_order') + 1);

            $examQuestion = CbtExamQuestion::query()->create([
                'exam_id' => $exam->id,
                'question_id' => $question->id,
                'sort_order' => $order,
                'marks' => $marksOverride ?? $question->marks,
                'type' => $question->type,
                'stem' => $question->stem,
                'explanation' => $question->explanation,
                'is_frozen' => false,
                'frozen_at' => null,
            ]);

            $this->copyOptions($examQuestion, $question);
            $this->recalculateExamTotals($exam);

            return $examQuestion->fresh(['options']) ?? $examQuestion;
        });
    }

    /**
     * Explicitly re-copy bank content into the draft exam snapshot.
     * Never runs automatically when the bank changes.
     */
    public function refreshFromBank(CbtExamQuestion $examQuestion): CbtExamQuestion
    {
        $this->guard->assertExamQuestionMutable($examQuestion);

        $examQuestion->loadMissing(['exam', 'question.options']);
        $question = $examQuestion->question;

        if ($question === null) {
            throw ValidationException::withMessages([
                'question_id' => 'Source question bank record is missing.',
            ]);
        }

        $this->questions->assertPersistedMcqValid($question);

        return DB::transaction(function () use ($examQuestion, $question) {
            $examQuestion->update([
                'marks' => $question->marks,
                'type' => $question->type,
                'stem' => $question->stem,
                'explanation' => $question->explanation,
            ]);

            $examQuestion->options()->delete();
            $this->copyOptions($examQuestion, $question);
            $this->recalculateExamTotals($examQuestion->exam);

            return $examQuestion->fresh(['options']) ?? $examQuestion;
        });
    }

    public function detach(CbtExamQuestion $examQuestion): void
    {
        $this->guard->assertExamQuestionMutable($examQuestion);

        DB::transaction(function () use ($examQuestion) {
            $exam = $examQuestion->exam;
            $examQuestion->options()->delete();
            $examQuestion->delete();
            $this->recalculateExamTotals($exam);
        });
    }

    public function reorder(CbtExam $exam, array $examQuestionIdsInOrder): void
    {
        $this->guard->assertExamEditable($exam);

        DB::transaction(function () use ($exam, $examQuestionIdsInOrder) {
            $ids = array_values(array_map('intval', $examQuestionIdsInOrder));
            $existing = $exam->examQuestions()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            $incoming = collect($ids)->sort()->values();

            if ($existing->all() !== $incoming->all()) {
                throw ValidationException::withMessages([
                    'exam_questions' => 'Reorder list must include every exam question exactly once.',
                ]);
            }

            // Two-phase update avoids unique(exam_id, sort_order) collisions while swapping.
            foreach ($ids as $index => $id) {
                CbtExamQuestion::query()->whereKey($id)->where('exam_id', $exam->id)->update([
                    'sort_order' => 100000 + $index,
                ]);
            }

            foreach ($ids as $index => $id) {
                CbtExamQuestion::query()->whereKey($id)->where('exam_id', $exam->id)->update([
                    'sort_order' => $index + 1,
                ]);
            }
        });
    }

    public function updateSnapshotMarks(CbtExamQuestion $examQuestion, float $marks): CbtExamQuestion
    {
        $this->guard->assertExamQuestionMutable($examQuestion);

        if ($marks <= 0) {
            throw ValidationException::withMessages([
                'marks' => 'Exam question marks must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($examQuestion, $marks) {
            $examQuestion->update(['marks' => $marks]);
            $this->recalculateExamTotals($examQuestion->exam);

            return $examQuestion->fresh(['options']) ?? $examQuestion;
        });
    }

    private function copyOptions(CbtExamQuestion $examQuestion, CbtQuestion $question): void
    {
        $question->loadMissing('options');

        foreach ($question->options->sortBy('sort_order')->values() as $index => $option) {
            /** @var CbtQuestionOption $option */
            CbtExamQuestionOption::query()->create([
                'exam_question_id' => $examQuestion->id,
                'source_option_id' => $option->id,
                'label' => $option->label,
                'body' => $option->body,
                'is_correct' => $option->is_correct,
                'sort_order' => $option->sort_order ?: ($index + 1),
            ]);
        }
    }

    public function recalculateExamTotals(CbtExam $exam): void
    {
        $questions = $exam->examQuestions()->get(['id', 'marks']);
        $exam->update([
            'question_count' => $questions->count(),
            'max_score' => round((float) $questions->sum(fn (CbtExamQuestion $q) => (float) $q->marks), 2),
        ]);
    }

    /**
     * Student-safe exam question payload (no is_correct / explanations that reveal answers).
     *
     * @return array{id: int, sort_order: int, marks: string, type: string, stem: string, options: list<array{id: int, label: ?string, body: string, sort_order: int}>}
     */
    public function studentSafeQuestion(CbtExamQuestion $examQuestion): array
    {
        return [
            'id' => $examQuestion->id,
            'sort_order' => $examQuestion->sort_order,
            'marks' => (string) $examQuestion->marks,
            'type' => $examQuestion->type instanceof CbtQuestionType
                ? $examQuestion->type->value
                : (string) $examQuestion->type,
            'stem' => $examQuestion->stem,
            'options' => $this->questions->studentSafeExamOptions($examQuestion)->all(),
        ];
    }
}
