<?php

namespace App\Services\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\CbtExamStatus;
use App\Http\Resources\Cbt\CbtStudentExamResource;
use App\Models\CbtAttempt;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Builds a student-safe offline exam package from frozen snapshots.
 * Browser continuity only — server remains authoritative for marking and identity.
 */
class CbtOfflinePackageService
{
    public const PACKAGE_VERSION = 1;

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'is_correct',
        'correct_option_id',
        'correct_option',
        'answer_key',
        'correctAnswer',
        'correct_answer',
        'correct',
        'solution',
        'explanation',
    ];

    public function __construct(
        private readonly CbtExamSnapshotService $snapshots,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildForOwner(CbtAttempt $attempt, User $user): array
    {
        if ((int) $attempt->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'attempt' => 'This attempt does not belong to the authenticated student.',
            ]);
        }

        $attempt->loadMissing(['exam.subject', 'exam.examQuestions.options', 'answers']);
        $exam = $attempt->exam;

        if ($exam === null) {
            throw ValidationException::withMessages([
                'exam' => 'Exam is missing for this attempt.',
            ]);
        }

        if ($exam->status !== CbtExamStatus::Published && $exam->status !== CbtExamStatus::Archived) {
            throw ValidationException::withMessages([
                'exam' => 'Only published (frozen) exams can be packaged for offline use.',
            ]);
        }

        if (! $exam->isConfigurationFrozen()) {
            throw ValidationException::withMessages([
                'exam' => 'Exam configuration is not frozen.',
            ]);
        }

        if ($attempt->status !== CbtAttemptStatus::InProgress) {
            throw ValidationException::withMessages([
                'attempt' => 'Only an active in-progress attempt can produce an offline package.',
            ]);
        }

        $questions = $exam->examQuestions;
        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'questions' => 'Exam has no frozen questions to package.',
            ]);
        }

        foreach ($questions as $question) {
            if ($question->options->isEmpty()) {
                throw ValidationException::withMessages([
                    'options' => 'Every exam question must include frozen options.',
                ]);
            }
        }

        $now = now();
        $secondsRemaining = null;
        if ($attempt->ends_at !== null) {
            $secondsRemaining = max(0, (int) $now->diffInSeconds($attempt->ends_at, false));
        }

        $examPayload = (new CbtStudentExamResource($exam))->resolve();
        $safeQuestions = $questions->map(fn ($q) => $this->snapshots->studentSafeQuestion($q))->values()->all();
        $examPayload['questions'] = $safeQuestions;
        $examPayload['is_frozen'] = true;

        $package = [
            'package_version' => self::PACKAGE_VERSION,
            'attempt' => [
                'id' => $attempt->id,
                'uuid' => $attempt->uuid,
                'exam_id' => $attempt->exam_id,
                'status' => $attempt->status->value,
                'mode' => $attempt->mode->value,
                'started_at' => optional($attempt->started_at)?->toIso8601String(),
                'ends_at' => optional($attempt->ends_at)?->toIso8601String(),
                'server_now' => $now->toIso8601String(),
                'seconds_remaining' => $secondsRemaining,
            ],
            'exam' => $examPayload,
            'answers' => $attempt->answers->map(fn ($answer) => [
                'exam_question_id' => $answer->exam_question_id,
                'selected_exam_option_id' => $answer->selected_exam_option_id,
                'answered_at' => optional($answer->answered_at)?->toIso8601String(),
            ])->values()->all(),
            'security' => [
                'server_authoritative' => true,
                'contains_answer_key' => false,
                'contains_scores' => false,
                'note' => 'Local storage is for continuity only and is not tamper-proof.',
            ],
        ];

        $this->assertStudentSafe($package);

        return $package;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    public function assertStudentSafe(array $package): void
    {
        $encoded = json_encode($package);
        if ($encoded === false) {
            throw ValidationException::withMessages([
                'package' => 'Offline package could not be serialized.',
            ]);
        }

        foreach (self::FORBIDDEN_KEYS as $key) {
            if (preg_match('/"'.preg_quote($key, '/').'"\s*:/', $encoded) === 1) {
                throw ValidationException::withMessages([
                    'package' => 'Offline package must not include answer-key field: '.$key,
                ]);
            }
        }

        $this->assertNoForbiddenKeysRecursive($package);

        $questions = $package['exam']['questions'] ?? null;
        if (! is_array($questions) || $questions === []) {
            throw ValidationException::withMessages([
                'questions' => 'Offline package requires frozen questions.',
            ]);
        }

        foreach ($questions as $question) {
            if (! is_array($question) || empty($question['options']) || ! is_array($question['options'])) {
                throw ValidationException::withMessages([
                    'options' => 'Offline package requires options for every question.',
                ]);
            }
        }

        if (empty($package['attempt']['ends_at'])) {
            throw ValidationException::withMessages([
                'ends_at' => 'Offline package requires an authoritative server deadline.',
            ]);
        }
    }

    /**
     * @param  mixed  $node
     */
    private function assertNoForbiddenKeysRecursive(mixed $node): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw ValidationException::withMessages([
                    'package' => 'Offline package must not include answer-key field: '.$key,
                ]);
            }
            $this->assertNoForbiddenKeysRecursive($value);
        }
    }
}
