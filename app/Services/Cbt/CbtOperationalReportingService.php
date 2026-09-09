<?php

namespace App\Services\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\CbtExamStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\OnlinePaymentPurpose;
use App\Enums\OnlinePaymentStatus;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtExamAssignment;
use App\Models\CbtExamQuestion;
use App\Models\CbtExamQuestionOption;
use App\Models\CbtQuestion;
use App\Models\CbtResult;
use App\Models\CbtResultAccess;
use App\Models\Enrollment;
use App\Models\OnlinePayment;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CbtOperationalReportingService
{
    public function dashboardSummary(): array
    {
        $now = now();

        $resultsTotal = CbtResult::query()->count();
        $unlocked = CbtResultAccess::query()->whereNull('revoked_at')->count();
        $locked = max(0, $resultsTotal - $unlocked);

        $checkerPayments = OnlinePayment::query()
            ->where('purpose', OnlinePaymentPurpose::CbtResultChecker);

        return [
            'total_exams' => CbtExam::query()->count(),
            'draft_exams' => CbtExam::query()->where('status', CbtExamStatus::Draft)->count(),
            'published_exams' => CbtExam::query()->where('status', CbtExamStatus::Published)->count(),
            'active_exams' => CbtExam::query()
                ->where('status', CbtExamStatus::Published)
                ->where('is_active', true)
                ->where(function ($q) use ($now) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                })
                ->count(),
            'questions' => CbtQuestion::query()->count(),
            'completed_attempts' => CbtAttempt::query()->where('status', CbtAttemptStatus::Submitted)->count(),
            'in_progress_attempts' => CbtAttempt::query()
                ->where('status', CbtAttemptStatus::InProgress)
                ->where(function ($q) use ($now) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                })
                ->count(),
            'expired_in_progress_attempts' => CbtAttempt::query()
                ->where('status', CbtAttemptStatus::InProgress)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', $now)
                ->count(),
            'results_generated' => $resultsTotal,
            'locked_results' => $locked,
            'unlocked_results' => $unlocked,
            'result_checker' => [
                'payments_paid' => (clone $checkerPayments)->where('status', OnlinePaymentStatus::Paid)->count(),
                'payments_pending' => (clone $checkerPayments)->where('status', OnlinePaymentStatus::Pending)->count(),
                'payments_failed' => (clone $checkerPayments)->where('status', OnlinePaymentStatus::Failed)->count(),
                'revenue_kobo' => (int) (clone $checkerPayments)->where('status', OnlinePaymentStatus::Paid)->sum('amount_kobo'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeExamMonitor(): array
    {
        $now = now();
        $exams = CbtExam::query()
            ->with(['subject:id,name', 'classSectionOffering.classSection.schoolClass'])
            ->where('status', CbtExamStatus::Published)
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('title')
            ->get();

        return $exams->map(fn (CbtExam $exam) => $this->examParticipationSnapshot($exam))->values()->all();
    }

    public function examParticipationSnapshot(CbtExam $exam): array
    {
        $now = now();
        $assignedIds = $this->assignedStudentIds($exam);
        $assigned = $assignedIds->count();

        $attempts = CbtAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('status', '!=', CbtAttemptStatus::Void)
            ->get(['id', 'student_profile_id', 'status', 'ends_at', 'started_at', 'submitted_at']);

        $startedStudents = $attempts->pluck('student_profile_id')->unique()->count();
        $inProgress = $attempts->filter(fn (CbtAttempt $a) => $a->status === CbtAttemptStatus::InProgress
            && ($a->ends_at === null || $a->ends_at->gt($now)))->count();
        $expired = $attempts->filter(fn (CbtAttempt $a) => $a->status === CbtAttemptStatus::InProgress
            && $a->ends_at !== null && $a->ends_at->lte($now))->count();
        $submitted = $attempts->where('status', CbtAttemptStatus::Submitted)->count();
        $abandoned = $attempts->where('status', CbtAttemptStatus::Abandoned)->count();
        $submittedStudents = $attempts->where('status', CbtAttemptStatus::Submitted)
            ->pluck('student_profile_id')->unique()->count();
        $notStarted = max(0, $assigned - $startedStudents);
        $completionPct = $assigned > 0
            ? round(($submittedStudents / $assigned) * 100, 1)
            : 0.0;

        $className = $exam->classSectionOffering?->classSection?->schoolClass?->name
            ?? $exam->classSectionOffering?->classSection?->name;

        return [
            'exam_id' => $exam->id,
            'title' => $exam->title,
            'subject' => $exam->subject?->name,
            'class' => $className,
            'starts_at' => optional($exam->starts_at)?->toIso8601String(),
            'ends_at' => optional($exam->ends_at)?->toIso8601String(),
            'assigned_students' => $assigned,
            'started_students' => $startedStudents,
            'not_started_students' => $notStarted,
            'attempts_in_progress' => $inProgress,
            'attempts_submitted' => $submitted,
            'attempts_expired' => $expired,
            'attempts_abandoned' => $abandoned,
            'completed_students' => $submittedStudents,
            'completion_percentage' => $completionPct,
        ];
    }

    public function examPerformance(CbtExam $exam): array
    {
        $participation = $this->examParticipationSnapshot($exam);
        $results = CbtResult::query()
            ->whereHas('attempt', fn ($q) => $q->where('exam_id', $exam->id)->where('status', CbtAttemptStatus::Submitted))
            ->get(['score', 'percentage', 'passed']);

        $passCount = $results->where('passed', true)->count();
        $failCount = $results->where('passed', false)->count();
        $count = $results->count();

        return array_merge($participation, [
            'total_results' => $count,
            'average_score' => $count ? round((float) $results->avg('score'), 2) : null,
            'highest_score' => $count ? round((float) $results->max('score'), 2) : null,
            'lowest_score' => $count ? round((float) $results->min('score'), 2) : null,
            'average_percentage' => $count ? round((float) $results->avg('percentage'), 2) : null,
            'pass_count' => $passCount,
            'fail_count' => $failCount,
            'pass_rate' => $count ? round(($passCount / $count) * 100, 1) : null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function examRoster(CbtExam $exam): array
    {
        $now = now();
        $assignedIds = $this->assignedStudentIds($exam);
        $students = StudentProfile::query()
            ->whereIn('id', $assignedIds->all())
            ->orderBy('surname')
            ->orderBy('first_name')
            ->get(['id', 'admission_number', 'surname', 'first_name', 'other_names']);

        $attempts = CbtAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('status', '!=', CbtAttemptStatus::Void)
            ->with('result:id,attempt_id,score,percentage,grade,passed')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_profile_id');

        return $students->map(function (StudentProfile $student) use ($attempts, $now) {
            /** @var Collection<int, CbtAttempt> $studentAttempts */
            $studentAttempts = $attempts->get($student->id, collect());
            $latest = $studentAttempts->first();
            $status = 'not_started';
            if ($latest !== null) {
                if ($latest->status === CbtAttemptStatus::Submitted) {
                    $status = 'submitted';
                } elseif ($latest->status === CbtAttemptStatus::Abandoned) {
                    $status = 'abandoned';
                } elseif ($latest->status === CbtAttemptStatus::InProgress
                    && $latest->ends_at !== null
                    && $latest->ends_at->lte($now)) {
                    $status = 'expired';
                } elseif ($latest->status === CbtAttemptStatus::InProgress) {
                    $status = 'in_progress';
                }
            }

            $result = $latest?->result;

            return [
                'student_id' => $student->id,
                'student_name' => $student->fullName(),
                'admission_number' => $student->admission_number,
                'status' => $status,
                'started_at' => optional($latest?->started_at)?->toIso8601String(),
                'submitted_at' => optional($latest?->submitted_at)?->toIso8601String(),
                'score' => $result?->score !== null ? (string) $result->score : null,
                'percentage' => $result?->percentage !== null ? (string) $result->percentage : null,
                'grade' => $result?->grade,
                'passed' => $result?->passed,
                'attempts_count' => $studentAttempts->count(),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function questionAnalytics(CbtExam $exam): array
    {
        $exam->loadMissing(['examQuestions.options']);
        $attemptIds = CbtAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('status', CbtAttemptStatus::Submitted)
            ->pluck('id');

        $answers = CbtAnswer::query()
            ->whereIn('attempt_id', $attemptIds)
            ->get(['attempt_id', 'exam_question_id', 'selected_exam_option_id']);

        $correctOptionIds = CbtExamQuestionOption::query()
            ->whereIn('exam_question_id', $exam->examQuestions->pluck('id'))
            ->where('is_correct', true)
            ->pluck('id', 'exam_question_id');

        $attemptCount = $attemptIds->count();

        return $exam->examQuestions->sortBy('sort_order')->values()->map(function (CbtExamQuestion $question) use ($answers, $correctOptionIds, $attemptCount) {
            $qAnswers = $answers->where('exam_question_id', $question->id);
            $answered = $qAnswers->whereNotNull('selected_exam_option_id')->count();
            $correctId = (int) ($correctOptionIds[$question->id] ?? 0);
            $correct = $qAnswers->where('selected_exam_option_id', $correctId)->count();
            $incorrect = max(0, $answered - $correct);
            $unanswered = max(0, $attemptCount - $answered);
            $pctCorrect = $attemptCount > 0 ? round(($correct / $attemptCount) * 100, 1) : 0.0;
            $difficulty = $pctCorrect >= 70 ? 'easy' : ($pctCorrect >= 40 ? 'moderate' : 'difficult');
            $stem = (string) $question->stem;
            $truncated = mb_strlen($stem) > 120 ? mb_substr($stem, 0, 117).'…' : $stem;

            return [
                'exam_question_id' => $question->id,
                'sort_order' => $question->sort_order,
                'stem_truncated' => $truncated,
                'marks' => (string) $question->marks,
                'answered' => $answered,
                'correct' => $correct,
                'incorrect' => $incorrect,
                'unanswered' => $unanswered,
                'percentage_correct' => $pctCorrect,
                'percentage_incorrect' => $attemptCount > 0 ? round(($incorrect / $attemptCount) * 100, 1) : 0.0,
                'analytics_difficulty' => $difficulty,
            ];
        })->all();
    }

    public function attemptsQuery(array $filters): Builder
    {
        $now = now();

        return CbtAttempt::query()
            ->with([
                'exam:id,title,subject_id,class_section_offering_id',
                'exam.subject:id,name',
                'studentProfile:id,admission_number,surname,first_name,other_names',
                'result:id,attempt_id,score,percentage,grade,passed',
            ])
            ->when($filters['exam_id'] ?? null, fn ($q, $id) => $q->where('exam_id', (int) $id))
            ->when($filters['student_id'] ?? null, fn ($q, $id) => $q->where('student_profile_id', (int) $id))
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->whereHas('exam', fn ($e) => $e->where('subject_id', (int) $id)))
            ->when($filters['class_id'] ?? null, fn ($q, $id) => $q->whereHas('exam.classSectionOffering.classSection', fn ($c) => $c->where('school_class_id', (int) $id)))
            ->when($filters['mode'] ?? null, fn ($q, $mode) => $q->where('mode', $mode))
            ->when($filters['sync_status'] ?? null, fn ($q, $status) => $q->where('sync_status', $status))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('started_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('started_at', '<=', $to))
            ->when($filters['status'] ?? null, function ($q, $status) use ($now) {
                return match ((string) $status) {
                    'submitted' => $q->where('status', CbtAttemptStatus::Submitted),
                    'abandoned' => $q->where('status', CbtAttemptStatus::Abandoned),
                    'void' => $q->where('status', CbtAttemptStatus::Void),
                    'in_progress' => $q->where('status', CbtAttemptStatus::InProgress)
                        ->where(fn ($qq) => $qq->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
                    'expired' => $q->where('status', CbtAttemptStatus::InProgress)
                        ->whereNotNull('ends_at')->where('ends_at', '<=', $now),
                    default => $q,
                };
            })
            ->orderByDesc('id');
    }

    public function studentHistoryQuery(array $filters): Builder
    {
        return CbtResult::query()
            ->with([
                'attempt.exam.subject',
                'attempt.exam.academicSession',
                'attempt.exam.term',
                'attempt.studentProfile',
                'access.onlinePayment',
            ])
            ->when($filters['student_id'] ?? null, fn ($q, $id) => $q->whereHas('attempt', fn ($a) => $a->where('student_profile_id', (int) $id)))
            ->when($filters['exam_id'] ?? null, fn ($q, $id) => $q->whereHas('attempt', fn ($a) => $a->where('exam_id', (int) $id)))
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->whereHas('attempt.exam', fn ($e) => $e->where('subject_id', (int) $id)))
            ->when($filters['academic_session_id'] ?? null, fn ($q, $id) => $q->whereHas('attempt.exam', fn ($e) => $e->where('academic_session_id', (int) $id)))
            ->when($filters['term_id'] ?? null, fn ($q, $id) => $q->whereHas('attempt.exam', fn ($e) => $e->where('term_id', (int) $id)))
            ->orderByDesc('id');
    }

    public function formatAttemptRow(CbtAttempt $attempt): array
    {
        $now = now();
        $status = $attempt->status?->value;
        if ($attempt->status === CbtAttemptStatus::InProgress
            && $attempt->ends_at !== null
            && $attempt->ends_at->lte($now)) {
            $status = 'expired';
        }

        return [
            'id' => $attempt->id,
            'uuid_short' => substr((string) $attempt->uuid, 0, 8),
            'student_name' => $attempt->studentProfile?->fullName(),
            'admission_number' => $attempt->studentProfile?->admission_number,
            'exam_id' => $attempt->exam_id,
            'exam_title' => $attempt->exam?->title,
            'subject' => $attempt->exam?->subject?->name,
            'started_at' => optional($attempt->started_at)?->toIso8601String(),
            'ends_at' => optional($attempt->ends_at)?->toIso8601String(),
            'submitted_at' => optional($attempt->submitted_at)?->toIso8601String(),
            'submission_reason' => $attempt->submission_reason,
            'status' => $status,
            'can_extend_timer' => $attempt->status === CbtAttemptStatus::InProgress,
            'mode' => $attempt->mode?->value ?? $attempt->mode,
            'sync_status' => $attempt->sync_status?->value ?? $attempt->sync_status,
            'score' => $attempt->result?->score !== null ? (string) $attempt->result->score : null,
            'percentage' => $attempt->result?->percentage !== null ? (string) $attempt->result->percentage : null,
            'result_available' => $attempt->result !== null,
        ];
    }

    public function formatStudentHistoryRow(CbtResult $result): array
    {
        $access = $result->access;
        $payment = $access?->onlinePayment;

        return [
            'result_id' => $result->id,
            'exam_title' => $result->attempt?->exam?->title,
            'subject' => $result->attempt?->exam?->subject?->name,
            'academic_session' => $result->attempt?->exam?->academicSession?->name,
            'term' => $result->attempt?->exam?->term?->name,
            'student_name' => $result->attempt?->studentProfile?->fullName(),
            'admission_number' => $result->attempt?->studentProfile?->admission_number,
            'marked_at' => optional($result->marked_at)?->toIso8601String(),
            'score' => (string) $result->score,
            'percentage' => (string) $result->percentage,
            'grade' => $result->grade,
            'passed' => (bool) $result->passed,
            'result_access' => $access && $access->revoked_at === null ? 'unlocked' : 'locked',
            'payment_status' => $payment?->status?->value,
            'payment_reference' => $payment?->reference,
        ];
    }

    public function csvResponse(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Collection<int, int>
     */
    public function assignedStudentIds(CbtExam $exam): Collection
    {
        $exam->loadMissing('assignments');
        $direct = $exam->assignments
            ->pluck('student_profile_id')
            ->filter()
            ->map(fn ($id) => (int) $id);

        $offeringIds = $exam->assignments
            ->pluck('class_section_offering_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $fromOfferings = collect();
        if ($offeringIds !== []) {
            $fromOfferings = Enrollment::query()
                ->where('status', EnrollmentStatus::Active)
                ->whereIn('class_section_offering_id', $offeringIds)
                ->pluck('student_profile_id')
                ->map(fn ($id) => (int) $id);
        }

        return $direct->merge($fromOfferings)->unique()->values();
    }
}
