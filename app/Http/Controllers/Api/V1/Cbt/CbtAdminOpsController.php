<?php

namespace App\Http\Controllers\Api\V1\Cbt;

use App\Http\Controllers\Controller;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtAttemptService;
use App\Services\Cbt\CbtExamService;
use App\Services\Cbt\CbtOperationalReportingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CbtAdminOpsController extends Controller
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly CbtOperationalReportingService $reports,
        private readonly CbtAttemptService $attempts,
        private readonly CbtExamService $exams,
    ) {}

    public function monitor(Request $request): JsonResponse
    {
        $this->assertOpsAccess($request);

        return ApiResponse::success('Active CBT exam monitor.', [
            'exams' => $this->reports->activeExamMonitor(),
            'server_now' => now()->toIso8601String(),
        ]);
    }

    public function examReport(Request $request, CbtExam $exam): JsonResponse
    {
        $this->assertOpsAccess($request);

        return ApiResponse::success('CBT exam performance report.', [
            'performance' => $this->reports->examPerformance($exam),
            'roster' => $this->reports->examRoster($exam),
            'questions' => $this->reports->questionAnalytics($exam),
        ]);
    }

    public function attempts(Request $request): JsonResponse
    {
        $this->assertOpsAccess($request);

        $filters = $request->only([
            'exam_id', 'student_id', 'subject_id', 'class_id', 'status', 'mode', 'sync_status', 'from', 'to',
        ]);
        $rows = $this->reports->attemptsQuery($filters)->paginate(20);

        return ApiResponse::success('CBT attempts retrieved.', [
            'items' => $rows->getCollection()->map(fn ($attempt) => $this->reports->formatAttemptRow($attempt))->values()->all(),
            'meta' => $this->pageMeta($rows),
        ]);
    }

    public function extendAttempt(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $this->assertOpsAccess($request);

        $validated = $request->validate([
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'reset' => ['sometimes', 'boolean'],
        ]);

        if (empty($validated['reset']) && empty($validated['minutes'])) {
            return ApiResponse::error('Provide minutes to extend, or reset=true.', status: 422);
        }

        $updated = $this->attempts->extendTimer($attempt, $validated);

        return ApiResponse::success('Attempt timer updated.', [
            'attempt' => $this->reports->formatAttemptRow($updated),
        ]);
    }

    public function extendExamTimers(Request $request, CbtExam $exam): JsonResponse
    {
        $this->assertOpsAccess($request);

        $validated = $request->validate([
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'reset' => ['sometimes', 'boolean'],
        ]);

        if (empty($validated['reset']) && empty($validated['minutes'])) {
            return ApiResponse::error('Provide minutes to extend, or reset=true.', status: 422);
        }

        $result = $this->attempts->extendTimersForExam($exam, $validated);

        return ApiResponse::success('In-progress attempt timers updated.', [
            'updated' => $result['updated'],
            'items' => collect($result['attempts'])->map(fn ($a) => $this->reports->formatAttemptRow($a))->values()->all(),
        ]);
    }

    public function updateExamSchedule(Request $request, CbtExam $exam): JsonResponse
    {
        abort_unless($this->access->canManage($request->user()), 403);

        $validated = $request->validate([
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['starts_at'], $validated['ends_at'])
            && $validated['starts_at'] !== null
            && $validated['ends_at'] !== null
            && strtotime((string) $validated['ends_at']) <= strtotime((string) $validated['starts_at'])
        ) {
            return ApiResponse::error('ends_at must be after starts_at.', status: 422);
        }

        $updated = $this->exams->updateSchedule($exam, $validated);

        return ApiResponse::success('Exam schedule updated for future attempts.', [
            'exam_id' => $updated->id,
            'duration_minutes' => $updated->duration_minutes,
            'starts_at' => optional($updated->starts_at)?->toIso8601String(),
            'ends_at' => optional($updated->ends_at)?->toIso8601String(),
            'is_active' => (bool) $updated->is_active,
            'note' => 'Existing in-progress timers were not changed. Use extend-timers for live attempts.',
        ]);
    }

    public function studentHistory(Request $request): JsonResponse
    {
        $this->assertOpsAccess($request);

        $filters = $request->only([
            'student_id', 'exam_id', 'subject_id', 'academic_session_id', 'term_id',
        ]);
        abort_unless(! empty($filters['student_id']), 422, 'student_id is required.');

        $rows = $this->reports->studentHistoryQuery($filters)->paginate(20);

        return ApiResponse::success('Student CBT history retrieved.', [
            'items' => $rows->getCollection()->map(fn ($result) => $this->reports->formatStudentHistoryRow($result))->values()->all(),
            'meta' => $this->pageMeta($rows),
        ]);
    }

    public function exportExamResults(Request $request, CbtExam $exam): StreamedResponse
    {
        $this->assertOpsAccess($request);

        $rows = $this->reports->examRoster($exam);

        return $this->reports->csvResponse(
            'cbt-exam-'.$exam->id.'-results.csv',
            ['Student', 'Admission No.', 'Status', 'Started', 'Submitted', 'Score', 'Percentage', 'Grade', 'Passed', 'Attempts'],
            collect($rows)->map(fn (array $row) => [
                $row['student_name'],
                $row['admission_number'],
                $row['status'],
                $row['started_at'],
                $row['submitted_at'],
                $row['score'],
                $row['percentage'],
                $row['grade'],
                $row['passed'] === null ? '' : ($row['passed'] ? 'yes' : 'no'),
                $row['attempts_count'],
            ]),
        );
    }

    public function exportExamAttendance(Request $request, CbtExam $exam): StreamedResponse
    {
        $this->assertOpsAccess($request);
        $roster = $this->reports->examRoster($exam);

        return $this->reports->csvResponse(
            'cbt-exam-'.$exam->id.'-attendance.csv',
            ['Student', 'Admission No.', 'Status', 'Started', 'Submitted'],
            collect($roster)->map(fn (array $row) => [
                $row['student_name'],
                $row['admission_number'],
                $row['status'],
                $row['started_at'],
                $row['submitted_at'],
            ]),
        );
    }

    public function exportStudentHistory(Request $request): StreamedResponse
    {
        $this->assertOpsAccess($request);
        $studentId = (int) $request->input('student_id');
        abort_unless($studentId > 0, 422, 'student_id is required.');

        $filters = $request->only(['student_id', 'exam_id', 'subject_id', 'academic_session_id', 'term_id']);
        $query = $this->reports->studentHistoryQuery($filters);

        return $this->reports->csvResponse(
            'cbt-student-'.$studentId.'-history.csv',
            ['Exam', 'Subject', 'Session', 'Term', 'Marked at', 'Score', 'Percentage', 'Grade', 'Passed', 'Access', 'Payment status', 'Payment reference'],
            $query->lazy(200)->map(function ($result) {
                $row = $this->reports->formatStudentHistoryRow($result);

                return [
                    $row['exam_title'],
                    $row['subject'],
                    $row['academic_session'],
                    $row['term'],
                    $row['marked_at'],
                    $row['score'],
                    $row['percentage'],
                    $row['grade'],
                    $row['passed'] ? 'yes' : 'no',
                    $row['result_access'],
                    $row['payment_status'],
                    $row['payment_reference'],
                ];
            }),
        );
    }

    private function assertOpsAccess(Request $request): void
    {
        abort_unless(
            $this->access->canManage($request->user()) || $this->access->canMark($request->user()),
            403,
        );
    }

    private function pageMeta($rows): array
    {
        return [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'from' => $rows->firstItem(),
            'to' => $rows->lastItem(),
            'total' => $rows->total(),
        ];
    }
}
