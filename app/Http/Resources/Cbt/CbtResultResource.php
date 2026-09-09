<?php

namespace App\Http\Resources\Cbt;

use App\Models\CbtResult;
use App\Models\CbtResultAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing CBT result — summary vs detailed controlled by unlock state.
 *
 * @mixin CbtResult
 */
class CbtResultResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly bool $unlocked = true,
        private readonly ?array $breakdown = null,
        private readonly ?CbtResultAccess $access = null,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var CbtResult $result */
        $result = $this->resource;
        $result->loadMissing([
            'attempt.exam.subject',
            'attempt.exam.academicSession',
            'attempt.exam.term',
            'attempt.studentProfile',
        ]);

        $exam = $result->attempt?->exam;
        $student = $result->attempt?->studentProfile;

        $base = [
            'id' => $result->id,
            'attempt_id' => $result->attempt_id,
            'exam_id' => $exam?->id,
            'exam_title' => $exam?->title,
            'subject' => $exam?->subject?->name,
            'academic_session' => $exam?->academicSession?->name,
            'term' => $exam?->term?->name,
            'marked_at' => optional($result->marked_at)?->toIso8601String(),
            'submitted_at' => optional($result->attempt?->submitted_at)?->toIso8601String(),
            'started_at' => optional($result->attempt?->started_at)?->toIso8601String(),
            'attempt_status' => $result->attempt?->status?->value,
            'details_unlocked' => $this->unlocked,
            'result_available' => true,
            'result_unlocked' => $this->unlocked,
        ];

        if (! $this->unlocked) {
            return array_merge($base, [
                'score' => null,
                'max_score' => null,
                'percentage' => null,
                'grade' => null,
                'passed' => null,
                'access_message' => 'Detailed result access requires a Result Checker.',
            ]);
        }

        $detailed = array_merge($base, [
            'student_name' => $student?->fullName(),
            'admission_number' => $student?->admission_number,
            'score' => (string) $result->score,
            'max_score' => (string) $result->max_score,
            'percentage' => (string) $result->percentage,
            'grade' => $result->grade,
            'passed' => (bool) $result->passed,
        ]);

        if ($this->breakdown !== null) {
            $detailed['performance'] = $this->breakdown;
        }

        if ($this->access !== null) {
            $detailed['unlocked_at'] = optional($this->access->granted_at)?->toIso8601String();
            $detailed['payment_reference'] = $this->access->onlinePayment?->reference;
        }

        return $detailed;
    }

    public function toSummary(bool $unlocked): array
    {
        return (new self($this->resource, $unlocked))->resolve();
    }

    public function toDetailed(array $breakdown, ?CbtResultAccess $access): array
    {
        return (new self($this->resource, true, $breakdown, $access))->resolve();
    }
}
