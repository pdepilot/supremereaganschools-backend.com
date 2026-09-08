<?php

namespace App\Http\Resources\Cbt;

use App\Models\CbtQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin question representation — includes is_correct for authorized managers only.
 *
 * @mixin CbtQuestion
 */
class CbtAdminQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CbtQuestion $question */
        $question = $this->resource;
        $question->loadMissing(['options', 'schoolClass', 'subject']);

        return [
            'id' => $question->id,
            'school_class_id' => $question->school_class_id,
            'school_class' => $question->schoolClass?->name,
            'subject_id' => $question->subject_id,
            'subject' => $question->subject?->name,
            'topic' => $question->topic,
            'difficulty' => $question->difficulty?->value ?? $question->difficulty,
            'type' => $question->type?->value ?? $question->type,
            'stem' => $question->stem,
            'marks' => (string) $question->marks,
            'explanation' => $question->explanation,
            'is_active' => (bool) $question->is_active,
            'created_by' => $question->created_by,
            'updated_at' => optional($question->updated_at)?->toIso8601String(),
            'options' => $question->options->map(fn ($option) => [
                'id' => $option->id,
                'label' => $option->label,
                'body' => $option->body,
                'is_correct' => (bool) $option->is_correct,
                'sort_order' => $option->sort_order,
            ])->values()->all(),
        ];
    }
}
