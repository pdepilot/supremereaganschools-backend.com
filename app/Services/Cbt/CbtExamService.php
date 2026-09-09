<?php

namespace App\Services\Cbt;

use App\Enums\CbtExamStatus;
use App\Models\CbtExam;
use App\Models\ClassSectionOffering;
use App\Models\Term;
use Illuminate\Validation\ValidationException;

class CbtExamService
{
    public function __construct(
        private readonly CbtExamConfigurationGuard $guard,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createDraft(array $attributes): CbtExam
    {
        $this->assertScope($attributes);

        if ((int) ($attributes['duration_minutes'] ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'Exam duration must be greater than zero.',
            ]);
        }

        return CbtExam::query()->create([
            'title' => $attributes['title'],
            'instructions' => $attributes['instructions'] ?? null,
            'subject_id' => $attributes['subject_id'],
            'class_section_offering_id' => $attributes['class_section_offering_id'],
            'academic_session_id' => $attributes['academic_session_id'],
            'term_id' => $attributes['term_id'],
            'starts_at' => $attributes['starts_at'] ?? null,
            'ends_at' => $attributes['ends_at'] ?? null,
            'duration_minutes' => (int) $attributes['duration_minutes'],
            'question_count' => 0,
            'pass_mark' => $attributes['pass_mark'] ?? null,
            'max_score' => 0,
            'randomize_questions' => (bool) ($attributes['randomize_questions'] ?? false),
            'randomize_options' => (bool) ($attributes['randomize_options'] ?? false),
            'max_attempts' => max(1, (int) ($attributes['max_attempts'] ?? 1)),
            'status' => CbtExamStatus::Draft,
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'write_to_assessment_score' => (bool) ($attributes['write_to_assessment_score'] ?? false),
            'created_by' => $attributes['created_by'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraft(CbtExam $exam, array $attributes): CbtExam
    {
        $this->guard->assertExamEditable($exam);

        $merged = array_merge($exam->only([
            'title',
            'instructions',
            'subject_id',
            'class_section_offering_id',
            'academic_session_id',
            'term_id',
            'starts_at',
            'ends_at',
            'duration_minutes',
            'pass_mark',
            'randomize_questions',
            'randomize_options',
            'max_attempts',
            'is_active',
            'write_to_assessment_score',
        ]), $attributes);

        $this->assertScope($merged);

        if ((int) $merged['duration_minutes'] <= 0) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'Exam duration must be greater than zero.',
            ]);
        }

        $exam->update([
            'title' => $merged['title'],
            'instructions' => $merged['instructions'] ?? null,
            'subject_id' => $merged['subject_id'],
            'class_section_offering_id' => $merged['class_section_offering_id'],
            'academic_session_id' => $merged['academic_session_id'],
            'term_id' => $merged['term_id'],
            'starts_at' => $merged['starts_at'] ?? null,
            'ends_at' => $merged['ends_at'] ?? null,
            'duration_minutes' => (int) $merged['duration_minutes'],
            'pass_mark' => $merged['pass_mark'] ?? null,
            'randomize_questions' => (bool) ($merged['randomize_questions'] ?? false),
            'randomize_options' => (bool) ($merged['randomize_options'] ?? false),
            'max_attempts' => max(1, (int) ($merged['max_attempts'] ?? 1)),
            'is_active' => (bool) ($merged['is_active'] ?? true),
            'write_to_assessment_score' => (bool) ($merged['write_to_assessment_score'] ?? false),
        ]);

        return $exam->fresh() ?? $exam;
    }

    /**
     * Adjust schedule/duration on a published exam for future attempts only.
     * Does not rewrite in-progress attempt timers (use extendTimersForExam for that).
     *
     * @param  array{duration_minutes?: int, starts_at?: mixed, ends_at?: mixed, is_active?: bool}  $attributes
     */
    public function updateSchedule(CbtExam $exam, array $attributes): CbtExam
    {
        if ($exam->status === CbtExamStatus::Archived) {
            throw ValidationException::withMessages([
                'exam' => 'Archived exams cannot have their schedule changed.',
            ]);
        }

        if ($exam->status !== CbtExamStatus::Published && $exam->status !== CbtExamStatus::Draft) {
            throw ValidationException::withMessages([
                'exam' => 'Only draft or published exams can have schedule updates.',
            ]);
        }

        $payload = [];

        if (array_key_exists('duration_minutes', $attributes)) {
            $duration = (int) $attributes['duration_minutes'];
            if ($duration <= 0) {
                throw ValidationException::withMessages([
                    'duration_minutes' => 'Exam duration must be greater than zero.',
                ]);
            }
            $payload['duration_minutes'] = $duration;
        }

        if (array_key_exists('starts_at', $attributes)) {
            $payload['starts_at'] = $attributes['starts_at'];
        }

        if (array_key_exists('ends_at', $attributes)) {
            $payload['ends_at'] = $attributes['ends_at'];
        }

        if (array_key_exists('is_active', $attributes)) {
            $payload['is_active'] = (bool) $attributes['is_active'];
        }

        if ($payload === []) {
            throw ValidationException::withMessages([
                'exam' => 'No schedule fields were provided.',
            ]);
        }

        $exam->update($payload);

        return $exam->fresh() ?? $exam;
    }

    public function archive(CbtExam $exam): CbtExam
    {
        if ($exam->status === CbtExamStatus::Draft) {
            throw ValidationException::withMessages([
                'exam' => 'Publish the exam before archiving, or keep it as a draft.',
            ]);
        }

        $exam->update([
            'status' => CbtExamStatus::Archived,
            'is_active' => false,
        ]);

        return $exam->fresh() ?? $exam;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertScope(array $attributes): void
    {
        $offering = ClassSectionOffering::query()->find($attributes['class_section_offering_id'] ?? null);
        if ($offering === null) {
            throw ValidationException::withMessages([
                'class_section_offering_id' => 'A valid class section offering is required.',
            ]);
        }

        if ((int) $offering->academic_session_id !== (int) $attributes['academic_session_id']) {
            throw ValidationException::withMessages([
                'academic_session_id' => 'Academic session must match the class section offering.',
            ]);
        }

        $term = Term::query()->find($attributes['term_id'] ?? null);
        if ($term === null) {
            throw ValidationException::withMessages([
                'term_id' => 'A valid term is required.',
            ]);
        }

        if ((int) $term->academic_session_id !== (int) $attributes['academic_session_id']) {
            throw ValidationException::withMessages([
                'term_id' => 'Term must belong to the exam academic session.',
            ]);
        }
    }
}
