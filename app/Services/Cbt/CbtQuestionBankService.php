<?php

namespace App\Services\Cbt;

use App\Enums\CbtQuestionDifficulty;
use App\Enums\CbtQuestionType;
use App\Models\CbtExamQuestion;
use App\Models\CbtExamQuestionOption;
use App\Models\CbtQuestion;
use App\Models\CbtQuestionOption;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CbtQuestionBankService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $options
     */
    public function create(array $attributes, array $options = []): CbtQuestion
    {
        $this->assertMcqOptions($attributes['type'] ?? CbtQuestionType::Mcq, $options);

        $question = CbtQuestion::query()->create([
            'school_class_id' => $attributes['school_class_id'],
            'subject_id' => $attributes['subject_id'],
            'topic' => $attributes['topic'] ?? null,
            'difficulty' => $attributes['difficulty'] ?? CbtQuestionDifficulty::Medium,
            'type' => $attributes['type'] ?? CbtQuestionType::Mcq,
            'stem' => $attributes['stem'],
            'marks' => $attributes['marks'] ?? 1,
            'explanation' => $attributes['explanation'] ?? null,
            'is_active' => $attributes['is_active'] ?? true,
            'created_by' => $attributes['created_by'] ?? null,
        ]);

        $this->replaceOptions($question, $options);

        return $question->fresh(['options']) ?? $question;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $options
     */
    public function update(CbtQuestion $question, array $attributes, ?array $options = null): CbtQuestion
    {
        $type = $attributes['type'] ?? $question->type;

        if ($options !== null) {
            $this->assertMcqOptions($type, $options);
        }

        $question->update([
            'school_class_id' => $attributes['school_class_id'] ?? $question->school_class_id,
            'subject_id' => $attributes['subject_id'] ?? $question->subject_id,
            'topic' => array_key_exists('topic', $attributes) ? $attributes['topic'] : $question->topic,
            'difficulty' => array_key_exists('difficulty', $attributes)
                ? ($attributes['difficulty'] ?? CbtQuestionDifficulty::Medium)
                : $question->difficulty,
            'type' => $type,
            'stem' => $attributes['stem'] ?? $question->stem,
            'marks' => $attributes['marks'] ?? $question->marks,
            'explanation' => array_key_exists('explanation', $attributes) ? $attributes['explanation'] : $question->explanation,
            'is_active' => array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : $question->is_active,
        ]);

        if ($options !== null) {
            $this->replaceOptions($question, $options);
        }

        return $question->fresh(['options']) ?? $question;
    }

    public function setActive(CbtQuestion $question, bool $active): CbtQuestion
    {
        $question->update(['is_active' => $active]);

        return $question->fresh(['options']) ?? $question;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    public function syncOptions(CbtQuestion $question, array $options): CbtQuestion
    {
        $this->assertMcqOptions($question->type, $options);
        $this->replaceOptions($question, $options);

        return $question->fresh(['options']) ?? $question;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    public function assertMcqOptions(CbtQuestionType|string $type, array $options): void
    {
        $typeValue = $type instanceof CbtQuestionType ? $type : CbtQuestionType::from((string) $type);

        if ($typeValue !== CbtQuestionType::Mcq) {
            return;
        }

        if (count($options) < 2) {
            throw ValidationException::withMessages([
                'options' => 'MCQ questions require at least two options.',
            ]);
        }

        $correct = 0;
        foreach ($options as $index => $option) {
            if (! filled($option['body'] ?? null)) {
                throw ValidationException::withMessages([
                    "options.{$index}.body" => 'Each option must have a body.',
                ]);
            }
            if (! empty($option['is_correct'])) {
                $correct++;
            }
        }

        if ($correct !== 1) {
            throw ValidationException::withMessages([
                'options' => 'MCQ questions must have exactly one correct option.',
            ]);
        }
    }

    /**
     * Validate an already-persisted bank question (and its options).
     */
    public function assertPersistedMcqValid(CbtQuestion $question): void
    {
        $question->loadMissing('options');
        $this->assertMcqOptions(
            $question->type,
            $question->options->map(fn (CbtQuestionOption $option) => [
                'body' => $option->body,
                'is_correct' => $option->is_correct,
            ])->all(),
        );

        if (! filled($question->stem)) {
            throw ValidationException::withMessages([
                'stem' => 'Question stem is required.',
            ]);
        }

        if ((float) $question->marks <= 0) {
            throw ValidationException::withMessages([
                'marks' => 'Question marks must be greater than zero.',
            ]);
        }
    }

    /**
     * Student-safe option list never includes is_correct.
     *
     * @return list<array{id: int, label: ?string, body: string, sort_order: int}>
     */
    public function studentSafeBankOptions(CbtQuestion $question): array
    {
        $question->loadMissing('options');

        return $question->options
            ->sortBy('sort_order')
            ->values()
            ->map(fn (CbtQuestionOption $option) => [
                'id' => $option->id,
                'label' => $option->label,
                'body' => $option->body,
                'sort_order' => $option->sort_order,
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function replaceOptions(CbtQuestion $question, array $options): void
    {
        $question->options()->delete();

        foreach (array_values($options) as $index => $option) {
            CbtQuestionOption::query()->create([
                'question_id' => $question->id,
                'label' => $option['label'] ?? null,
                'body' => $option['body'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'sort_order' => (int) ($option['sort_order'] ?? ($index + 1)),
            ]);
        }
    }

    /**
     * @return Collection<int, array{id: int, label: ?string, body: string, sort_order: int}>
     */
    public function studentSafeExamOptions(CbtExamQuestion $examQuestion): Collection
    {
        $examQuestion->loadMissing('options');

        return $examQuestion->options
            ->sortBy('sort_order')
            ->values()
            ->map(fn (CbtExamQuestionOption $option) => [
                'id' => $option->id,
                'label' => $option->label,
                'body' => $option->body,
                'sort_order' => $option->sort_order,
            ]);
    }
}
