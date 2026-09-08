<?php

namespace App\Services\Cbt;

use App\Enums\AssessmentKind;
use App\Enums\EnrollmentStatus;
use App\Models\AssessmentScore;
use App\Models\AssessmentType;
use App\Models\CbtAttempt;
use App\Models\CbtResult;
use App\Models\Enrollment;
use App\Services\AssessmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Isolated optional bridge from CBT results into AssessmentScore.
 *
 * Scaling assumption: CBT percentage is mapped onto the Examination assessment
 * type's max_score (typically 70), so CBT does not invent a fourth assessment kind
 * and does not overwrite First/Second CA rows.
 */
class CbtAssessmentScoreIntegrationService
{
    public function __construct(
        private readonly AssessmentService $assessments,
    ) {}

    public function syncIfEnabled(CbtResult $result): ?AssessmentScore
    {
        $result->loadMissing(['attempt.exam', 'attempt.enrollment', 'attempt.studentProfile']);

        $attempt = $result->attempt;
        $exam = $attempt?->exam;

        if ($exam === null || ! $exam->write_to_assessment_score) {
            return null;
        }

        $enrollment = $this->resolveEnrollment($attempt);
        if ($enrollment === null) {
            throw ValidationException::withMessages([
                'enrollment_id' => 'Cannot write AssessmentScore without an active enrollment.',
            ]);
        }

        $type = AssessmentType::query()->where('kind', AssessmentKind::Examination)->first();
        if ($type === null) {
            throw ValidationException::withMessages([
                'assessment_type' => 'Examination assessment type is not configured.',
            ]);
        }

        $scaled = round(((float) $result->percentage / 100) * (float) $type->max_score, 2);

        return DB::transaction(function () use ($result, $attempt, $exam, $enrollment, $type, $scaled) {
            if ($result->assessment_score_id) {
                $existing = AssessmentScore::query()->find($result->assessment_score_id);
                if ($existing !== null) {
                    $existing->update(['score' => $scaled]);
                    $this->recalculate($enrollment, $exam, $type);

                    return $existing->fresh() ?? $existing;
                }
            }

            $score = AssessmentScore::query()->updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'term_id' => $exam->term_id,
                    'subject_id' => $exam->subject_id,
                    'assessment_type_id' => $type->id,
                ],
                [
                    'score' => $scaled,
                    'entered_by' => $attempt->user_id,
                ],
            );

            $result->update(['assessment_score_id' => $score->id]);
            $this->recalculate($enrollment, $exam, $type);

            return $score->fresh() ?? $score;
        });
    }

    private function resolveEnrollment(CbtAttempt $attempt): ?Enrollment
    {
        if ($attempt->enrollment_id) {
            $enrollment = Enrollment::query()->find($attempt->enrollment_id);
            if ($enrollment !== null) {
                return $enrollment;
            }
        }

        return Enrollment::query()
            ->where('student_profile_id', $attempt->student_profile_id)
            ->where('status', EnrollmentStatus::Active)
            ->where('class_section_offering_id', $attempt->exam->class_section_offering_id)
            ->first();
    }

    private function recalculate(Enrollment $enrollment, $exam, AssessmentType $type): void
    {
        $this->assessments->recalculateSubject(
            (int) $enrollment->class_section_offering_id,
            (int) $exam->subject_id,
            (int) $exam->term_id,
        );
        $this->assessments->recalculateOfferingSummaries(
            (int) $enrollment->class_section_offering_id,
            (int) $exam->term_id,
        );
    }
}
