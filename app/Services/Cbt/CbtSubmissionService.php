<?php

namespace App\Services\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\CbtSubmissionReason;
use App\Enums\CbtSyncStatus;
use App\Models\CbtAttempt;
use App\Models\CbtResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CbtSubmissionService
{
    public function __construct(
        private readonly CbtAttemptService $attempts,
        private readonly CbtMarkingService $marking,
        private readonly CbtGradingService $grading,
        private readonly CbtAssessmentScoreIntegrationService $assessmentScores,
    ) {}

    /**
     * @param  array{client_submitted_at?: mixed, reason?: string|CbtSubmissionReason|null}  $options
     */
    public function submit(CbtAttempt $attempt, User $user, array $options = []): CbtResult
    {
        $this->attempts->assertOwnedBy($attempt, $user);

        return DB::transaction(function () use ($attempt, $user, $options) {
            /** @var CbtAttempt $attempt */
            $attempt = CbtAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            // Historical results are immutable — sync/retry must never remake scores.
            $existingResult = $attempt->result()->first();
            if ($existingResult !== null) {
                if ($attempt->status !== CbtAttemptStatus::Submitted) {
                    $attempt->update([
                        'status' => CbtAttemptStatus::Submitted,
                        'submitted_at' => $attempt->submitted_at ?? now(),
                        'client_submitted_at' => $options['client_submitted_at'] ?? $attempt->client_submitted_at,
                        'sync_status' => CbtSyncStatus::Synced,
                    ]);
                }

                return $existingResult;
            }

            if ($attempt->status !== CbtAttemptStatus::InProgress) {
                throw ValidationException::withMessages([
                    'attempt' => 'Only in-progress attempts can be submitted.',
                ]);
            }

            $submittedAt = now();
            $marked = $this->marking->mark($attempt->load(['exam.examQuestions.options', 'answers.selectedExamOption']));
            $graded = $this->grading->gradeForPercentage($marked['percentage']);
            $passMark = $attempt->exam->pass_mark !== null ? (float) $attempt->exam->pass_mark : null;
            $passed = $this->grading->passed($passMark, $marked['percentage']);

            $result = CbtResult::query()->create([
                'attempt_id' => $attempt->id,
                'score' => $marked['score'],
                'max_score' => $marked['max_score'],
                'percentage' => $marked['percentage'],
                'grade' => $graded['grade'],
                'passed' => $passed,
                'marked_at' => $submittedAt,
            ]);

            // ends_at remains the original server-authoritative window; never rewritten here.
            $attempt->update([
                'status' => CbtAttemptStatus::Submitted,
                'submitted_at' => $submittedAt,
                'submission_reason' => $this->resolveReason($options['reason'] ?? null)->value,
                'client_submitted_at' => $options['client_submitted_at'] ?? $attempt->client_submitted_at,
                'sync_status' => CbtSyncStatus::Synced,
            ]);

            $this->assessmentScores->syncIfEnabled($result->fresh(['attempt.exam']) ?? $result);

            return $result->fresh(['attempt', 'assessmentScore']) ?? $result;
        });
    }

    private function resolveReason(mixed $reason): CbtSubmissionReason
    {
        if ($reason instanceof CbtSubmissionReason) {
            return $reason;
        }

        if (is_string($reason) && $reason !== '') {
            return CbtSubmissionReason::tryFrom($reason) ?? CbtSubmissionReason::StudentManual;
        }

        return CbtSubmissionReason::StudentManual;
    }
}
