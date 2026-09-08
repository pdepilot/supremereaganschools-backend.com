<?php

namespace App\Http\Resources\Cbt;

use App\Enums\CbtExamStatus;
use App\Models\CbtExam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CbtExam
 */
class CbtAdminExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CbtExam $exam */
        $exam = $this->resource;
        $exam->loadMissing([
            'subject',
            'academicSession',
            'term',
            'classSectionOffering.classSection.schoolClass',
            'examQuestions.options',
            'examQuestions.question',
            'assignments.studentProfile',
            'assignments.classSectionOffering.classSection',
            'creator',
        ]);

        $now = now();
        $availability = 'draft';
        if ($exam->status === CbtExamStatus::Published) {
            if ($exam->starts_at && $now->lt($exam->starts_at)) {
                $availability = 'upcoming';
            } elseif ($exam->ends_at && $now->gte($exam->ends_at)) {
                $availability = 'closed';
            } else {
                $availability = 'active';
            }
        } elseif ($exam->status === CbtExamStatus::Archived) {
            $availability = 'archived';
        }

        return [
            'id' => $exam->id,
            'title' => $exam->title,
            'instructions' => $exam->instructions,
            'subject_id' => $exam->subject_id,
            'subject' => $exam->subject?->name,
            'academic_session_id' => $exam->academic_session_id,
            'academic_session' => $exam->academicSession?->name,
            'term_id' => $exam->term_id,
            'term' => $exam->term?->name,
            'class_section_offering_id' => $exam->class_section_offering_id,
            'class_section_offering' => $exam->classSectionOffering?->classSection?->name
                ?? ('Offering #'.$exam->class_section_offering_id),
            'starts_at' => optional($exam->starts_at)?->toIso8601String(),
            'ends_at' => optional($exam->ends_at)?->toIso8601String(),
            'duration_minutes' => $exam->duration_minutes,
            'question_count' => $exam->question_count,
            'pass_mark' => $exam->pass_mark !== null ? (string) $exam->pass_mark : null,
            'max_score' => (string) $exam->max_score,
            'randomize_questions' => (bool) $exam->randomize_questions,
            'randomize_options' => (bool) $exam->randomize_options,
            'max_attempts' => $exam->max_attempts,
            'status' => $exam->status?->value ?? (string) $exam->status,
            'published_at' => optional($exam->published_at)?->toIso8601String(),
            'is_active' => (bool) $exam->is_active,
            'write_to_assessment_score' => (bool) $exam->write_to_assessment_score,
            'is_frozen' => $exam->isConfigurationFrozen(),
            'availability' => $availability,
            'assignment_count' => $exam->assignments->count(),
            'attempt_count' => (int) ($exam->attempts_count ?? $exam->attempts()->count()),
            'frozen_at' => optional($exam->examQuestions->firstWhere('is_frozen', true)?->frozen_at
                ?? $exam->published_at)?->toIso8601String(),
            'created_by' => $exam->created_by,
            'creator' => $exam->creator?->name,
            'exam_questions' => $exam->examQuestions->map(function ($eq) {
                $sourceChanged = $eq->question
                    && ! $eq->is_frozen
                    && $eq->question->updated_at
                    && $eq->updated_at
                    && $eq->question->updated_at->gt($eq->updated_at);

                return [
                    'id' => $eq->id,
                    'question_id' => $eq->question_id,
                    'sort_order' => $eq->sort_order,
                    'marks' => (string) $eq->marks,
                    'type' => $eq->type?->value ?? $eq->type,
                    'stem' => $eq->stem,
                    'explanation' => $eq->explanation,
                    'is_frozen' => (bool) $eq->is_frozen,
                    'frozen_at' => optional($eq->frozen_at)?->toIso8601String(),
                    'source_changed' => (bool) $sourceChanged,
                    'options' => $eq->options->map(fn ($option) => [
                        'id' => $option->id,
                        'label' => $option->label,
                        'body' => $option->body,
                        'is_correct' => (bool) $option->is_correct,
                        'sort_order' => $option->sort_order,
                        'source_option_id' => $option->source_option_id,
                    ])->values()->all(),
                ];
            })->values()->all(),
            'assignments' => $exam->assignments->map(fn ($row) => [
                'id' => $row->id,
                'type' => $row->student_profile_id ? 'student' : 'offering',
                'class_section_offering_id' => $row->class_section_offering_id,
                'class_section_offering' => $row->classSectionOffering?->classSection?->name,
                'student_profile_id' => $row->student_profile_id,
                'student' => $row->studentProfile?->fullName(),
                'admission_number' => $row->studentProfile?->admission_number,
                'assigned_by' => $row->assigned_by,
                'created_at' => optional($row->created_at)?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
