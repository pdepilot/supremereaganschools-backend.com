<?php

namespace App\Http\Resources\Cbt;

use App\Models\CbtAttempt;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtResultCheckerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CbtAttempt
 */
class CbtAttemptResource extends JsonResource
{
    /**
     * @param  bool|null  $resultUnlocked  Explicit unlock state; null resolves from auth + Result Checker rules.
     */
    public function __construct(
        $resource,
        private readonly ?bool $resultUnlocked = null,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var CbtAttempt $attempt */
        $attempt = $this->resource;
        $attempt->loadMissing(['answers', 'result', 'exam.subject', 'exam.examQuestions.options']);

        $now = now();
        $secondsRemaining = null;
        if ($attempt->ends_at !== null && $attempt->status->value === 'in_progress') {
            $secondsRemaining = max(0, (int) $now->diffInSeconds($attempt->ends_at, false));
        }

        return [
            'id' => $attempt->id,
            'uuid' => $attempt->uuid,
            'exam_id' => $attempt->exam_id,
            'status' => $attempt->status->value,
            'mode' => $attempt->mode->value,
            'started_at' => optional($attempt->started_at)?->toIso8601String(),
            'ends_at' => optional($attempt->ends_at)?->toIso8601String(),
            'submitted_at' => optional($attempt->submitted_at)?->toIso8601String(),
            'submission_reason' => $attempt->submission_reason,
            'server_now' => $now->toIso8601String(),
            'seconds_remaining' => $secondsRemaining,
            'answers' => $attempt->answers->map(fn ($answer) => [
                'exam_question_id' => $answer->exam_question_id,
                'selected_exam_option_id' => $answer->selected_exam_option_id,
                'answered_at' => optional($answer->answered_at)?->toIso8601String(),
            ])->values()->all(),
            'result' => $attempt->result
                ? (new CbtResultResource(
                    $attempt->result,
                    $this->resolveResultUnlocked($request, $attempt),
                ))->resolve()
                : null,
            'exam' => $attempt->exam
                ? (new CbtStudentExamResource($attempt->exam))->resolve()
                : null,
        ];
    }

    private function resolveResultUnlocked(Request $request, CbtAttempt $attempt): bool
    {
        if ($this->resultUnlocked !== null) {
            return $this->resultUnlocked;
        }

        if ($attempt->result === null) {
            return false;
        }

        $user = $request->user();
        if ($user === null) {
            return false;
        }

        $access = app(CbtAccessService::class);

        // Staff/ops retain full result visibility under existing authorization.
        if ($access->canManage($user) || $access->canMark($user) || $access->canProctor($user)) {
            return true;
        }

        $student = $access->studentProfileFor($user);
        if ($student === null) {
            return false;
        }

        return app(CbtResultCheckerService::class)->accountHasPaidAccess($attempt->result, $student);
    }
}
