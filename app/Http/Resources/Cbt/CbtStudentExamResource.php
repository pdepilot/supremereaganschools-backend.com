<?php

namespace App\Http\Resources\Cbt;

use App\Enums\CbtExamStatus;
use App\Models\CbtExam;
use App\Services\Cbt\CbtExamSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-safe exam package — never includes is_correct or answer keys.
 *
 * @mixin CbtExam
 */
class CbtStudentExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CbtExam $exam */
        $exam = $this->resource;
        $exam->loadMissing(['subject', 'examQuestions.options']);

        $snapshots = app(CbtExamSnapshotService::class);

        return [
            'id' => $exam->id,
            'title' => $exam->title,
            'instructions' => $exam->instructions,
            'subject' => $exam->subject ? [
                'id' => $exam->subject->id,
                'name' => $exam->subject->name,
                'code' => $exam->subject->code,
            ] : null,
            'duration_minutes' => $exam->duration_minutes,
            'starts_at' => optional($exam->starts_at)?->toIso8601String(),
            'ends_at' => optional($exam->ends_at)?->toIso8601String(),
            'question_count' => $exam->question_count,
            'max_score' => (string) $exam->max_score,
            'pass_mark' => $exam->pass_mark !== null ? (string) $exam->pass_mark : null,
            'max_attempts' => $exam->max_attempts,
            'status' => $exam->status instanceof CbtExamStatus ? $exam->status->value : (string) $exam->status,
            'questions' => $exam->examQuestions->map(
                fn ($question) => $snapshots->studentSafeQuestion($question)
            )->values()->all(),
        ];
    }
}
