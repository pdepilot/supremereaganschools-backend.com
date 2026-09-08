<?php

namespace App\Services\Cbt;

use App\Enums\CbtExamStatus;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use Illuminate\Validation\ValidationException;

/**
 * Domain guard: published/archived exam snapshots are immutable.
 */
class CbtExamConfigurationGuard
{
    public function assertExamEditable(CbtExam $exam): void
    {
        if ($exam->isConfigurationFrozen()) {
            throw ValidationException::withMessages([
                'exam' => 'Published or archived exam configuration cannot be modified. Create a new exam instead.',
            ]);
        }

        if ($exam->status !== CbtExamStatus::Draft) {
            throw ValidationException::withMessages([
                'exam' => 'Only draft exams can be edited.',
            ]);
        }
    }

    public function assertExamQuestionMutable(CbtExamQuestion $examQuestion): void
    {
        if ($examQuestion->is_frozen) {
            throw ValidationException::withMessages([
                'exam_question' => 'Frozen exam question snapshots cannot be modified.',
            ]);
        }

        $examQuestion->loadMissing('exam');
        $this->assertExamEditable($examQuestion->exam);
    }
}
