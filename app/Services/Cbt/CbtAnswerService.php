<?php

namespace App\Services\Cbt;

use App\Enums\CbtSyncStatus;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExamQuestion;
use App\Models\CbtExamQuestionOption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CbtAnswerService
{
    public function __construct(
        private readonly CbtAttemptService $attempts,
    ) {}

    /**
     * Upsert an answer against the exam-scoped question identity.
     *
     * @param  array{exam_question_id: int, selected_exam_option_id?: ?int, client_answered_at?: mixed}  $payload
     */
    public function save(CbtAttempt $attempt, User $user, array $payload): CbtAnswer
    {
        $this->attempts->assertOwnedBy($attempt, $user);
        $this->attempts->assertAcceptsAnswers($attempt);

        $examQuestionId = (int) ($payload['exam_question_id'] ?? 0);
        $selectedOptionId = array_key_exists('selected_exam_option_id', $payload)
            ? ($payload['selected_exam_option_id'] !== null ? (int) $payload['selected_exam_option_id'] : null)
            : null;

        $examQuestion = CbtExamQuestion::query()->find($examQuestionId);
        if ($examQuestion === null || (int) $examQuestion->exam_id !== (int) $attempt->exam_id) {
            throw ValidationException::withMessages([
                'exam_question_id' => 'Exam question does not belong to this attempt.',
            ]);
        }

        if ($selectedOptionId !== null) {
            $option = CbtExamQuestionOption::query()->find($selectedOptionId);
            if ($option === null || (int) $option->exam_question_id !== (int) $examQuestion->id) {
                throw ValidationException::withMessages([
                    'selected_exam_option_id' => 'Selected option does not belong to this exam question.',
                ]);
            }
        }

        return DB::transaction(function () use ($attempt, $examQuestion, $selectedOptionId, $payload) {
            $answer = CbtAnswer::query()->updateOrCreate(
                [
                    'attempt_id' => $attempt->id,
                    'exam_question_id' => $examQuestion->id,
                ],
                [
                    'question_id' => $examQuestion->question_id,
                    'selected_exam_option_id' => $selectedOptionId,
                    'answered_at' => now(),
                    'client_answered_at' => $payload['client_answered_at'] ?? null,
                    'sync_status' => CbtSyncStatus::Synced,
                ],
            );

            return $answer->fresh(['examQuestion', 'selectedExamOption']) ?? $answer;
        });
    }
}
