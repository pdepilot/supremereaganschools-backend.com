<?php

namespace App\Services\Cbt;

use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExamQuestion;
use App\Models\CbtExamQuestionOption;

class CbtMarkingService
{
    /**
     * Mark against frozen exam snapshots only — never against the live question bank.
     *
     * @return array{score: float, max_score: float, percentage: float, per_question: list<array{exam_question_id: int, marks: float, earned: float, correct: bool}>}
     */
    public function mark(CbtAttempt $attempt): array
    {
        $attempt->loadMissing(['exam.examQuestions.options', 'answers.selectedExamOption']);

        $answersByQuestion = $attempt->answers->keyBy('exam_question_id');
        $score = 0.0;
        $maxScore = 0.0;
        $perQuestion = [];

        foreach ($attempt->exam->examQuestions as $examQuestion) {
            /** @var CbtExamQuestion $examQuestion */
            $marks = (float) $examQuestion->marks;
            $maxScore += $marks;

            /** @var CbtAnswer|null $answer */
            $answer = $answersByQuestion->get($examQuestion->id);
            $earned = 0.0;
            $correct = false;

            if ($answer?->selected_exam_option_id) {
                $option = $answer->selectedExamOption
                    ?? $examQuestion->options->firstWhere('id', $answer->selected_exam_option_id);

                if ($option instanceof CbtExamQuestionOption && $option->is_correct) {
                    $earned = $marks;
                    $correct = true;
                }
            }

            $score += $earned;
            $perQuestion[] = [
                'exam_question_id' => $examQuestion->id,
                'marks' => $marks,
                'earned' => $earned,
                'correct' => $correct,
            ];
        }

        $maxScore = round($maxScore, 2);
        $score = round($score, 2);
        $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0.0;

        return [
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'per_question' => $perQuestion,
        ];
    }
}
