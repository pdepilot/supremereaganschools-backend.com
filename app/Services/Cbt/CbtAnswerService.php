<?php

namespace App\Services\Cbt;

use App\Enums\CbtSyncStatus;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExamQuestion;
use App\Models\CbtExamQuestionOption;
use App\Models\User;
use Carbon\Carbon;
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
        return $this->saveWithMeta($attempt, $user, $payload, enforceClientOrdering: false)['answer'];
    }

    /**
     * Sync-path upsert with last-write-wins on client_answered_at (ordering hint only).
     *
     * @param  array{exam_question_id: int, selected_exam_option_id?: ?int|null, client_answered_at?: mixed}  $payload
     * @return array{answer: CbtAnswer, applied: bool, reason: ?string}
     */
    public function saveFromSync(CbtAttempt $attempt, User $user, array $payload): array
    {
        return $this->saveWithMeta($attempt, $user, $payload, enforceClientOrdering: true);
    }

    /**
     * @param  array{exam_question_id: int, selected_exam_option_id?: ?int|null, client_answered_at?: mixed}  $payload
     * @return array{answer: CbtAnswer, applied: bool, reason: ?string}
     */
    private function saveWithMeta(CbtAttempt $attempt, User $user, array $payload, bool $enforceClientOrdering): array
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

        $incomingClientAt = $this->parseClientAnsweredAt($payload['client_answered_at'] ?? null);

        return DB::transaction(function () use ($attempt, $examQuestion, $selectedOptionId, $incomingClientAt, $enforceClientOrdering) {
            /** @var CbtAnswer|null $locked */
            $locked = CbtAnswer::query()
                ->where('attempt_id', $attempt->id)
                ->where('exam_question_id', $examQuestion->id)
                ->lockForUpdate()
                ->first();

            if ($enforceClientOrdering && $locked !== null && $incomingClientAt !== null && $locked->client_answered_at !== null) {
                if ($locked->client_answered_at->gte($incomingClientAt)) {
                    return [
                        'answer' => $locked->fresh(['examQuestion', 'selectedExamOption']) ?? $locked,
                        'applied' => false,
                        'reason' => 'stale_client_event',
                    ];
                }
            }

            if ($locked === null) {
                $locked = new CbtAnswer([
                    'attempt_id' => $attempt->id,
                    'exam_question_id' => $examQuestion->id,
                ]);
            }

            $locked->fill([
                'question_id' => $examQuestion->question_id,
                'selected_exam_option_id' => $selectedOptionId,
                'answered_at' => now(),
                'client_answered_at' => $incomingClientAt,
                'sync_status' => CbtSyncStatus::Synced,
            ]);
            $locked->save();

            return [
                'answer' => $locked->fresh(['examQuestion', 'selectedExamOption']) ?? $locked,
                'applied' => true,
                'reason' => null,
            ];
        });
    }

    private function parseClientAnsweredAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'client_answered_at' => 'client_answered_at must be a valid timestamp.',
            ]);
        }
    }
}
