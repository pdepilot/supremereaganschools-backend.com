<?php

namespace App\Http\Resources\Cbt;

use App\Models\CbtResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing result summary — no answer keys.
 *
 * @mixin CbtResult
 */
class CbtResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CbtResult $result */
        $result = $this->resource;
        $result->loadMissing(['attempt.exam.subject']);

        $exam = $result->attempt?->exam;

        return [
            'id' => $result->id,
            'attempt_id' => $result->attempt_id,
            'exam_id' => $exam?->id,
            'exam_title' => $exam?->title,
            'subject' => $exam?->subject?->name,
            'score' => (string) $result->score,
            'max_score' => (string) $result->max_score,
            'percentage' => (string) $result->percentage,
            'grade' => $result->grade,
            'passed' => (bool) $result->passed,
            'marked_at' => optional($result->marked_at)?->toIso8601String(),
            'submitted_at' => optional($result->attempt?->submitted_at)?->toIso8601String(),
        ];
    }
}
