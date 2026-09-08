<?php

namespace App\Http\Resources\Cbt;

use App\Models\CbtAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CbtAttempt
 */
class CbtAttemptResource extends JsonResource
{
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
            'server_now' => $now->toIso8601String(),
            'seconds_remaining' => $secondsRemaining,
            'answers' => $attempt->answers->map(fn ($answer) => [
                'exam_question_id' => $answer->exam_question_id,
                'selected_exam_option_id' => $answer->selected_exam_option_id,
                'answered_at' => optional($answer->answered_at)?->toIso8601String(),
            ])->values()->all(),
            'result' => $attempt->result ? (new CbtResultResource($attempt->result))->resolve() : null,
            'exam' => $attempt->exam
                ? (new CbtStudentExamResource($attempt->exam))->resolve()
                : null,
        ];
    }
}
