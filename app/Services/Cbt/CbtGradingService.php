<?php

namespace App\Services\Cbt;

use App\Models\GradeScale;

/**
 * Reuses the school's GradeScale catalogue (same bands as AssessmentService).
 * Does not invent a parallel grading table or band set.
 */
class CbtGradingService
{
    /**
     * @return array{grade: ?string, remark: ?string}
     */
    public function gradeForPercentage(float $percentage): array
    {
        $clamped = max(0.0, min(100.0, $percentage));
        $scale = GradeScale::forScore($clamped);

        return [
            'grade' => $scale?->grade,
            'remark' => $scale?->remark,
        ];
    }

    public function passed(?float $passMark, float $percentage): bool
    {
        if ($passMark === null) {
            return $percentage >= 50.0;
        }

        return $percentage >= (float) $passMark;
    }
}
