<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AttendanceRecord;
use App\Models\ClassSectionOffering;
use App\Models\Enrollment;
use App\Models\SchoolSetting;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffReportService
{
    public function __construct(
        private readonly PeopleAccessService $access,
        private readonly AssessmentService $assessments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function catalogue(User $user): array
    {
        $user->loadMissing('staffProfile');
        $settings = SchoolSetting::query()->with('currentTerm')->first();
        $contexts = $this->assessments->contexts($user);
        $termId = $settings?->current_term_id;
        $sessionId = $settings?->current_academic_session_id;

        $terms = Term::query()
            ->when($sessionId, fn ($query) => $query->where('academic_session_id', $sessionId))
            ->orderBy('term_number')
            ->get()
            ->map(fn (Term $term) => [
                'id' => $term->id,
                'name' => $term->name,
                'starts_on' => $term->starts_on?->toDateString(),
                'ends_on' => $term->ends_on?->toDateString(),
            ])
            ->values()
            ->all();

        $currentTerm = collect($terms)->firstWhere('id', $termId) ?? ($terms[0] ?? null);

        return [
            'name' => $user->name,
            'staff_number' => $user->staffProfile?->staff_number,
            'school' => $settings?->name ?: 'Supreme Reagan Schools',
            'current_term_id' => $termId,
            'current_academic_session_id' => $sessionId,
            'from' => $currentTerm['starts_on'] ?? Carbon::now('Africa/Lagos')->subDays(30)->toDateString(),
            'to' => $currentTerm['ends_on'] ?? Carbon::now('Africa/Lagos')->toDateString(),
            'kinds' => [
                ['slug' => 'roll', 'label' => 'Class list', 'copy' => 'Every pupil on the form.'],
                ['slug' => 'attendance', 'label' => 'Attendance', 'copy' => 'Present, late, and absent over a date range.'],
                ['slug' => 'marks', 'label' => 'Mark register', 'copy' => 'Scores for a paper you teach.'],
                ['slug' => 'results', 'label' => 'Term results', 'copy' => 'CA, exam, and grade for the term.'],
            ],
            'offerings' => $contexts['offerings'],
            'assessment_types' => $contexts['assessment_types'],
            'sessions' => $contexts['sessions'],
            'terms' => $terms,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generate(User $user, array $filters): array
    {
        $offeringId = (int) $filters['class_section_offering_id'];
        $this->assertAssigned($user, $offeringId);

        return match ((string) $filters['kind']) {
            'roll' => $this->roll($user, $offeringId),
            'attendance' => $this->attendance($user, $offeringId, $filters),
            'marks' => $this->marks($user, $offeringId, $filters, false),
            'results' => $this->marks($user, $offeringId, $filters, true),
            default => throw ValidationException::withMessages([
                'kind' => 'That report is not available.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{filename: string, csv: string, title: string}
     */
    public function export(User $user, array $filters): array
    {
        $report = $this->generate($user, $filters);

        return [
            'filename' => $report['filename'],
            'title' => $report['title'],
            'csv' => $this->toCsv($report['columns'], $report['rows']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roll(User $user, int $offeringId): array
    {
        $offering = $this->offering($offeringId);
        $form = $offering->classSection?->name ?: 'Form';
        $rows = $this->enrollments($offeringId)->values()->map(function (Enrollment $enrollment, int $index) {
            $student = $enrollment->student;

            return [
                'index' => $index + 1,
                'admission_number' => $student?->admission_number,
                'full_name' => $student?->fullName(),
                'gender' => $student?->gender?->value,
                'status' => $student?->status?->value,
            ];
        })->all();

        return $this->payload(
            'roll',
            'Class list · '.$form,
            $form,
            [
                ['key' => 'index', 'label' => '#'],
                ['key' => 'admission_number', 'label' => 'Admission'],
                ['key' => 'full_name', 'label' => 'Pupil'],
                ['key' => 'gender', 'label' => 'Gender'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            $rows,
            ['pupils' => count($rows)],
            $user,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function attendance(User $user, int $offeringId, array $filters): array
    {
        if (! $this->access->canViewAttendanceForOffering($user, $offeringId)) {
            throw new AuthorizationException;
        }

        $offering = $this->offering($offeringId);
        $form = $offering->classSection?->name ?: 'Form';
        $from = (string) ($filters['from'] ?? Carbon::now('Africa/Lagos')->subDays(30)->toDateString());
        $to = (string) ($filters['to'] ?? Carbon::now('Africa/Lagos')->toDateString());
        $enrollments = $this->enrollments($offeringId);

        $records = AttendanceRecord::query()
            ->where('class_section_offering_id', $offeringId)
            ->whereDate('marked_on', '>=', $from)
            ->whereDate('marked_on', '<=', $to)
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get(['enrollment_id', 'status']);

        $grouped = $records->groupBy('enrollment_id');
        $rows = $enrollments->values()->map(function (Enrollment $enrollment, int $index) use ($grouped) {
            $days = $grouped->get($enrollment->id, collect());
            $present = $days->filter(fn (AttendanceRecord $row) => $row->status === AttendanceStatus::Present)->count();
            $late = $days->filter(fn (AttendanceRecord $row) => $row->status === AttendanceStatus::Late)->count();
            $absent = $days->filter(fn (AttendanceRecord $row) => $row->status === AttendanceStatus::Absent)->count();
            $marked = $days->count();
            $percent = $marked === 0 ? null : (int) round((($present + $late) / $marked) * 100);

            return [
                'index' => $index + 1,
                'admission_number' => $enrollment->student?->admission_number,
                'full_name' => $enrollment->student?->fullName(),
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'marked' => $marked,
                'percent' => $percent,
            ];
        })->all();

        $marked = collect($rows)->sum('marked');
        $present = collect($rows)->sum('present') + collect($rows)->sum('late');

        return $this->payload(
            'attendance',
            'Attendance · '.$form.' · '.$from.' to '.$to,
            $form,
            [
                ['key' => 'index', 'label' => '#'],
                ['key' => 'admission_number', 'label' => 'Admission'],
                ['key' => 'full_name', 'label' => 'Pupil'],
                ['key' => 'present', 'label' => 'Present'],
                ['key' => 'late', 'label' => 'Late'],
                ['key' => 'absent', 'label' => 'Absent'],
                ['key' => 'marked', 'label' => 'Days marked'],
                ['key' => 'percent', 'label' => 'Attendance %'],
            ],
            $rows,
            [
                'from' => $from,
                'to' => $to,
                'pupils' => count($rows),
                'percent' => $marked === 0 ? null : (int) round(($present / $marked) * 100),
            ],
            $user,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function marks(User $user, int $offeringId, array $filters, bool $finalView): array
    {
        $subjectId = (int) ($filters['subject_id'] ?? 0);
        if ($subjectId < 1) {
            throw ValidationException::withMessages([
                'subject_id' => 'Choose a subject for this report.',
            ]);
        }

        $register = $this->assessments->register(
            $offeringId,
            $subjectId,
            isset($filters['term_id']) ? (int) $filters['term_id'] : null,
            isset($filters['academic_session_id']) ? (int) $filters['academic_session_id'] : null,
            isset($filters['assessment_type_id']) ? (int) $filters['assessment_type_id'] : null,
            $finalView,
            $user,
        );

        $form = $register['form'] ?: 'Form';
        $subject = $register['subject_name'] ?: 'Subject';
        $kind = $finalView ? 'results' : 'marks';
        $paper = $finalView
            ? ($register['term_name'] ?: 'Term')
            : (($register['assessment_type']['name'] ?? null) ?: 'Scores');

        $columns = $finalView
            ? [
                ['key' => 'index', 'label' => '#'],
                ['key' => 'admission_number', 'label' => 'Admission'],
                ['key' => 'full_name', 'label' => 'Pupil'],
                ['key' => 'ca_total', 'label' => 'CA'],
                ['key' => 'exam_score', 'label' => 'Exam'],
                ['key' => 'total', 'label' => 'Total'],
                ['key' => 'grade', 'label' => 'Grade'],
                ['key' => 'remark', 'label' => 'Remark'],
            ]
            : [
                ['key' => 'index', 'label' => '#'],
                ['key' => 'admission_number', 'label' => 'Admission'],
                ['key' => 'full_name', 'label' => 'Pupil'],
                ['key' => 'score', 'label' => 'Score'],
                ['key' => 'grade', 'label' => 'Grade'],
                ['key' => 'remark', 'label' => 'Remark'],
            ];

        $rows = collect($register['students'])->map(function (array $student) use ($finalView) {
            return $finalView
                ? [
                    'index' => $student['index'],
                    'admission_number' => $student['admission_number'],
                    'full_name' => $student['full_name'],
                    'ca_total' => $student['ca_total'],
                    'exam_score' => $student['exam_score'],
                    'total' => $student['total'],
                    'grade' => $student['grade'],
                    'remark' => $student['remark'],
                ]
                : [
                    'index' => $student['index'],
                    'admission_number' => $student['admission_number'],
                    'full_name' => $student['full_name'],
                    'score' => $student['score'],
                    'grade' => $student['grade'],
                    'remark' => $student['remark'],
                ];
        })->all();

        return $this->payload(
            $kind,
            ($finalView ? 'Term results' : 'Mark register').' · '.$form.' · '.$subject.' · '.$paper,
            $form,
            $columns,
            $rows,
            array_merge($register['summary'], [
                'subject' => $subject,
                'term' => $register['term_name'],
                'paper' => $paper,
            ]),
            $user,
        );
    }

    private function offering(int $offeringId): ClassSectionOffering
    {
        return ClassSectionOffering::query()
            ->with(['classSection', 'academicSession'])
            ->findOrFail($offeringId);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Enrollment>
     */
    private function enrollments(int $offeringId)
    {
        return Enrollment::query()
            ->with('student')
            ->where('class_section_offering_id', $offeringId)
            ->where('status', EnrollmentStatus::Active)
            ->orderBy('id')
            ->get();
    }

    private function assertAssigned(User $user, int $offeringId): void
    {
        if (! $this->access->canViewClassroomOffering($user, $offeringId)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function payload(
        string $kind,
        string $title,
        string $form,
        array $columns,
        array $rows,
        array $summary,
        User $user,
    ): array {
        return [
            'kind' => $kind,
            'title' => $title,
            'form' => $form,
            'filename' => Str::slug($title).'.csv',
            'generated_at' => Carbon::now('Africa/Lagos')->toIso8601String(),
            'generated_by' => $user->name,
            'columns' => $columns,
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function toCsv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_column($columns, 'label'));

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column['key']] ?? '';
                $line[] = is_bool($value) ? ($value ? 'yes' : 'no') : $value;
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return "\u{FEFF}".$csv;
    }
}
