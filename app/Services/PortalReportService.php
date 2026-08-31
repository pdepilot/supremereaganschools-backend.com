<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\StaffStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\AdmissionApplication;
use App\Models\AttendanceRecord;
use App\Models\ClassSectionOffering;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolSetting;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PortalReportService
{
    public function __construct(private readonly LevelDeskService $levelDesks) {}

    /**
     * @return array<string, mixed>
     */
    public function assay(User $user): array
    {
        $settings = SchoolSetting::query()->with(['currentAcademicSession', 'currentTerm'])->first();
        $session = $settings?->currentAcademicSession;
        $term = $settings?->currentTerm;
        $sessionId = $settings?->current_academic_session_id;
        $termId = $settings?->current_term_id;
        $now = Carbon::now('Africa/Lagos');

        $attendance = $this->weekAttendance($now);
        $ledger = $this->ledger($sessionId, $termId);
        $admissions = $this->newAdmissions($session);
        $staff = $this->staffPulse($now);
        $pupils = StudentProfile::query()->where('status', StudentStatus::Active)->count();
        $wings = $this->levelDesks->all();

        return [
            'name' => trim($user->name) !== '' ? trim($user->name) : 'Administrator',
            'school' => $settings?->name ?: (string) config('app.name'),
            'session' => $session?->name,
            'term' => $term?->name,
            'drawn_at' => $now->toIso8601String(),
            'metrics' => [
                'attendance_percent' => $attendance['percent'],
                'attendance_delta' => $attendance['delta'],
                'fees_percent' => $ledger['percent'],
                'fees_count' => $ledger['collected']['count'],
                'fees_prefix' => $ledger['collected']['prefix'],
                'fees_suffix' => $ledger['collected']['suffix'],
                'fees_label' => $ledger['collected']['label'],
                'fees_delta' => $ledger['delta'],
                'admissions' => $admissions,
                'admissions_delta' => $session?->name ? 'This session' : 'No session sealed',
                'staff_percent' => $staff['percent'],
                'staff_present' => $staff['present'],
                'staff_expected' => $staff['expected'],
                'staff_delta' => $staff['delta'],
            ],
            'cells' => [
                [
                    'key' => 'roll',
                    'tag' => 'Roll',
                    'title' => 'Enrolment assay',
                    'value' => $pupils,
                    'copy' => $pupils === 1 ? '1 pupil on the books' : $pupils.' pupils on the books',
                ],
                [
                    'key' => 'fees',
                    'tag' => 'Ledger',
                    'title' => 'Fee collection',
                    'value' => $ledger['collected']['label'],
                    'copy' => $ledger['delta'],
                ],
                [
                    'key' => 'attendance',
                    'tag' => 'Week',
                    'title' => 'Attendance',
                    'value' => $attendance['percent'] === null ? '—' : $attendance['percent'].'%',
                    'copy' => $attendance['delta'],
                ],
                [
                    'key' => 'staff',
                    'tag' => 'Staff',
                    'title' => 'Masters’ presence',
                    'value' => $staff['present'],
                    'copy' => $staff['delta'],
                ],
            ],
            'wings' => array_map(function (array $wing) {
                $metrics = $wing['metrics'] ?? [];

                return [
                    'slug' => $wing['slug'],
                    'name' => $wing['name'],
                    'pupils' => $metrics['pupils'] ?? 0,
                    'forms' => $metrics['forms'] ?? 0,
                    'attendance_percent' => $metrics['attendance_percent'] ?? null,
                    'outstanding' => $metrics['outstanding'] ?? '₦0',
                ];
            }, $wings),
            'ledger' => [
                'invoiced' => $ledger['invoiced']['label'],
                'collected' => $ledger['collected']['label'],
                'outstanding' => $ledger['outstanding']['label'],
                'percent' => $ledger['percent'],
                'paid_in_full_count' => $ledger['paid_in_full_count'],
                'partially_paid_count' => $ledger['partially_paid_count'],
                'outstanding_count' => $ledger['outstanding_count'],
            ],
            'pipeline' => $this->pipeline($sessionId),
            'week' => $attendance['days'],
        ];
    }

    /**
     * @return array{percent: float|null, delta: string, days: list<array<string, mixed>>}
     */
    private function weekAttendance(Carbon $now): array
    {
        $start = $now->copy()->startOfWeek();
        $end = $now->copy()->endOfWeek();

        $records = AttendanceRecord::query()
            ->whereBetween('marked_on', [$start->toDateString(), $end->toDateString()])
            ->get(['status', 'marked_on']);

        $byDay = $records->groupBy(fn (AttendanceRecord $record) => $record->marked_on?->toDateString());
        $days = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $future = $cursor->isAfter($now->copy()->startOfDay());
            $rows = $byDay->get($key, collect());
            $total = $rows->count();
            $in = $rows->filter(
                fn (AttendanceRecord $record) => in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true),
            )->count();

            $days[] = [
                'date' => $key,
                'label' => $cursor->timezone('Africa/Lagos')->format('D j'),
                'percent' => $future || $total === 0 ? null : round(($in / $total) * 100, 1),
                'marked' => $total,
                'in' => $in,
                'future' => $future,
            ];
        }

        $weekTotal = $records->count();
        $weekIn = $records->filter(
            fn (AttendanceRecord $record) => in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true),
        )->count();

        if ($weekTotal === 0) {
            return [
                'percent' => null,
                'delta' => 'No roll marked this week',
                'days' => $days,
            ];
        }

        return [
            'percent' => round(($weekIn / $weekTotal) * 100, 1),
            'delta' => 'This week · '.$weekTotal.($weekTotal === 1 ? ' mark' : ' marks'),
            'days' => $days,
        ];
    }

    /**
     * @return array{
     *     invoiced: array{count: float|int, prefix: string, suffix: string, label: string},
     *     collected: array{count: float|int, prefix: string, suffix: string, label: string},
     *     outstanding: array{count: float|int, prefix: string, suffix: string, label: string},
     *     percent: int|null,
     *     paid_in_full_count: int,
     *     partially_paid_count: int,
     *     outstanding_count: int,
     *     delta: string
     * }
     */
    private function ledger(?int $sessionId, ?int $termId): array
    {
        $invoices = Invoice::query()
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->when($termId, fn ($query) => $query->where('term_id', $termId))
            ->when(! $termId && $sessionId, fn ($query) => $query->where('academic_session_id', $sessionId));

        $invoicedKobo = (int) (clone $invoices)->sum('total_kobo');
        $outstandingKobo = (int) (clone $invoices)
            ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Partial->value])
            ->selectRaw('COALESCE(SUM(total_kobo - paid_kobo), 0) as remaining')
            ->value('remaining');
        $paidInFull = (int) (clone $invoices)->where('status', InvoiceStatus::Paid->value)->count();
        $partial = (int) (clone $invoices)->where('status', InvoiceStatus::Partial->value)->count();
        $unpaid = (int) (clone $invoices)->where('status', InvoiceStatus::Unpaid->value)->count();

        $collectedKobo = (int) Payment::query()
            ->where('status', PaymentStatus::Posted)
            ->whereHas('invoice', function ($invoice) use ($sessionId, $termId) {
                $invoice->where('status', '!=', InvoiceStatus::Void->value)
                    ->when($termId, fn ($query) => $query->where('term_id', $termId))
                    ->when(! $termId && $sessionId, fn ($query) => $query->where('academic_session_id', $sessionId));
            })
            ->sum('amount_kobo');

        $percent = $invoicedKobo > 0 ? (int) round(($collectedKobo / $invoicedKobo) * 100) : null;
        $termLabel = SchoolSetting::query()->with('currentTerm')->first()?->currentTerm?->name;

        return [
            'invoiced' => Money::compactNaira($invoicedKobo),
            'collected' => Money::compactNaira($collectedKobo),
            'outstanding' => Money::compactNaira($outstandingKobo),
            'percent' => $percent,
            'paid_in_full_count' => $paidInFull,
            'partially_paid_count' => $partial,
            'outstanding_count' => $unpaid,
            'delta' => match (true) {
                $invoicedKobo === 0 => 'No invoices on the ledger',
                $percent === null => 'Posted collections',
                $termLabel => $percent.'% · '.$termLabel,
                default => $percent.'% of the ledger',
            },
        ];
    }

    private function newAdmissions(mixed $session): int
    {
        if ($session === null || $session->starts_on === null || $session->ends_on === null) {
            return 0;
        }

        return StudentProfile::query()
            ->whereIn('status', [StudentStatus::Active, StudentStatus::Pending])
            ->whereBetween('admitted_on', [$session->starts_on->toDateString(), $session->ends_on->toDateString()])
            ->count();
    }

    /**
     * @return array{percent: float|null, present: int, expected: int, delta: string}
     */
    private function staffPulse(Carbon $now): array
    {
        $expected = StaffProfile::query()->where('status', StaffStatus::Active)->count();
        $present = (int) AttendanceRecord::query()
            ->whereDate('marked_on', $now->toDateString())
            ->whereNotNull('marked_by')
            ->distinct()
            ->count('marked_by');

        if ($expected === 0) {
            return [
                'percent' => null,
                'present' => $present,
                'expected' => 0,
                'delta' => 'No staff on the books',
            ];
        }

        if ($present === 0) {
            return [
                'percent' => null,
                'present' => 0,
                'expected' => $expected,
                'delta' => $expected.' on the books · no roll today',
            ];
        }

        return [
            'percent' => round(($present / $expected) * 100, 1),
            'present' => $present,
            'expected' => $expected,
            'delta' => $present.' of '.$expected.' took the roll today',
        ];
    }

    /**
     * @return list<array{status: string, label: string, count: int}>
     */
    private function pipeline(?int $sessionId): array
    {
        $counts = AdmissionApplication::query()
            ->when($sessionId, fn ($query) => $query->where('academic_session_id', $sessionId))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(ApplicationStatus::cases())
            ->map(fn (ApplicationStatus $status) => [
                'status' => $status->value,
                'label' => match ($status) {
                    ApplicationStatus::Submitted => 'Submitted',
                    ApplicationStatus::UnderReview => 'Under review',
                    ApplicationStatus::ExamScheduled => 'Exam scheduled',
                    ApplicationStatus::Offered => 'Offered',
                    ApplicationStatus::Admitted => 'Admitted',
                    ApplicationStatus::Rejected => 'Rejected',
                    ApplicationStatus::Withdrawn => 'Withdrawn',
                },
                'count' => (int) ($counts[$status->value] ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogue(): array
    {
        $settings = SchoolSetting::query()->with(['currentAcademicSession', 'currentTerm'])->first();
        $sessionId = $settings?->current_academic_session_id;
        $termId = $settings?->current_term_id;
        $now = Carbon::now('Africa/Lagos');

        $sessions = AcademicSession::query()
            ->with(['terms' => fn ($query) => $query->orderBy('term_number')])
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (AcademicSession $session) => [
                'id' => $session->id,
                'name' => $session->name,
                'terms' => $session->terms->map(fn (Term $term) => [
                    'id' => $term->id,
                    'name' => $term->name,
                    'starts_on' => $term->starts_on?->toDateString(),
                    'ends_on' => $term->ends_on?->toDateString(),
                ])->values()->all(),
            ])
            ->values()
            ->all();

        $offerings = ClassSectionOffering::query()
            ->with(['classSection', 'academicSession'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (ClassSectionOffering $offering) => [
                'id' => $offering->id,
                'name' => $offering->classSection?->name ?: 'Form',
                'academic_session_id' => $offering->academic_session_id,
                'session_name' => $offering->academicSession?->name,
            ])
            ->values()
            ->all();

        $currentSession = collect($sessions)->firstWhere('id', $sessionId);
        $currentTerm = collect($currentSession['terms'] ?? [])->firstWhere('id', $termId)
            ?? (($currentSession['terms'] ?? [])[0] ?? null);

        return [
            'school' => $settings?->name ?: (string) config('app.name'),
            'current_academic_session_id' => $sessionId,
            'current_term_id' => $termId,
            'from' => $currentTerm['starts_on'] ?? $now->copy()->startOfWeek()->toDateString(),
            'to' => $now->toDateString(),
            'kinds' => [
                ['slug' => 'roll', 'label' => 'Enrolment', 'copy' => 'Pupils on the books by form.'],
                ['slug' => 'fees', 'label' => 'Fees', 'copy' => 'Expected, paid, and still due.'],
                ['slug' => 'attendance', 'label' => 'Attendance', 'copy' => 'Present, late, and absent.'],
                ['slug' => 'staff', 'label' => 'Staff', 'copy' => 'Who took the roll.'],
            ],
            'sessions' => $sessions,
            'offerings' => $offerings,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generate(User $user, array $filters): array
    {
        return match ((string) $filters['kind']) {
            'roll' => $this->enrolmentReport($user, $filters),
            'fees' => $this->feesReport($user, $filters),
            'attendance' => $this->attendanceReport($user, $filters),
            'staff' => $this->staffReport($user, $filters),
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
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function enrolmentReport(User $user, array $filters): array
    {
        $sessionId = $this->sessionId($filters);
        $offeringId = (int) ($filters['class_section_offering_id'] ?? 0);
        $enrollments = $this->enrollments($sessionId, $offeringId > 0 ? $offeringId : null);
        $form = $this->formLabel($offeringId, $sessionId);

        $rows = $enrollments->values()->map(function (Enrollment $enrollment, int $index) {
            return [
                'index' => $index + 1,
                'admission_number' => $enrollment->student?->admission_number,
                'full_name' => $enrollment->student?->fullName(),
                'gender' => $enrollment->student?->gender?->value,
                'form' => $enrollment->classSectionOffering?->classSection?->name,
                'status' => $enrollment->student?->status?->value,
            ];
        })->all();

        return $this->payload(
            'roll',
            'Enrolment · '.$form,
            $form,
            [
                ['key' => 'index', 'label' => '#'],
                ['key' => 'admission_number', 'label' => 'Admission'],
                ['key' => 'full_name', 'label' => 'Pupil'],
                ['key' => 'gender', 'label' => 'Gender'],
                ['key' => 'form', 'label' => 'Class'],
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
    private function feesReport(User $user, array $filters): array
    {
        $sessionId = $this->sessionId($filters);
        $termId = isset($filters['term_id']) ? (int) $filters['term_id'] : 0;
        $offeringId = (int) ($filters['class_section_offering_id'] ?? 0);
        $status = InvoiceStatus::fromFilter(isset($filters['status']) ? (string) $filters['status'] : null);

        $query = Invoice::query()
            ->with([
                'student',
                'term',
                'academicSession',
                'enrollment.classSectionOffering.classSection',
            ])
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->when($sessionId, fn ($builder) => $builder->where('academic_session_id', $sessionId))
            ->when($termId > 0, fn ($builder) => $builder->where('term_id', $termId))
            ->when($status, fn ($builder) => $builder->where('status', $status->value))
            ->when($offeringId > 0, function ($builder) use ($offeringId) {
                $builder->whereHas(
                    'enrollment',
                    fn ($enrollment) => $enrollment->where('class_section_offering_id', $offeringId),
                );
            })
            ->orderBy('id');

        $invoices = $query->get();
        $form = $this->formLabel($offeringId, $sessionId);
        $termName = $termId > 0 ? Term::query()->find($termId)?->name : 'All terms';

        $rows = $invoices->values()->map(function (Invoice $invoice, int $index) {
            return [
                'index' => $index + 1,
                'admission_number' => $invoice->student?->admission_number,
                'full_name' => $invoice->student?->fullName(),
                'form' => $invoice->enrollment?->classSectionOffering?->classSection?->name,
                'session' => $invoice->academicSession?->name,
                'term' => $invoice->term?->name,
                'fees' => Money::formatNaira((int) $invoice->total_kobo),
                'paid' => Money::formatNaira((int) $invoice->paid_kobo),
                'balance' => Money::formatNaira($invoice->remainingKobo()),
                'status' => $invoice->status?->feeStatusLabel(),
            ];
        })->all();

        $expected = (int) $invoices->sum('total_kobo');
        $collected = (int) $invoices->sum('paid_kobo');

        return $this->payload(
            'fees',
            'Fee collection · '.$form.' · '.($termName ?: 'Term'),
            $form,
            [
                ['key' => 'index', 'label' => '#'],
                ['key' => 'admission_number', 'label' => 'Admission'],
                ['key' => 'full_name', 'label' => 'Pupil'],
                ['key' => 'form', 'label' => 'Class'],
                ['key' => 'term', 'label' => 'Term'],
                ['key' => 'fees', 'label' => 'Fees'],
                ['key' => 'paid', 'label' => 'Paid'],
                ['key' => 'balance', 'label' => 'Balance'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            $rows,
            [
                'pupils' => count($rows),
                'expected' => Money::formatNaira($expected),
                'collected' => Money::formatNaira($collected),
                'outstanding' => Money::formatNaira(max(0, $expected - $collected)),
                'paid_in_full' => $invoices->where('status', InvoiceStatus::Paid)->count(),
                'partially_paid' => $invoices->where('status', InvoiceStatus::Partial)->count(),
                'outstanding_count' => $invoices->where('status', InvoiceStatus::Unpaid)->count(),
            ],
            $user,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function attendanceReport(User $user, array $filters): array
    {
        $sessionId = $this->sessionId($filters);
        $offeringId = (int) ($filters['class_section_offering_id'] ?? 0);
        $from = (string) ($filters['from'] ?? Carbon::now('Africa/Lagos')->startOfWeek()->toDateString());
        $to = (string) ($filters['to'] ?? Carbon::now('Africa/Lagos')->toDateString());
        $enrollments = $this->enrollments($sessionId, $offeringId > 0 ? $offeringId : null);
        $form = $this->formLabel($offeringId, $sessionId);

        $records = AttendanceRecord::query()
            ->whereDate('marked_on', '>=', $from)
            ->whereDate('marked_on', '<=', $to)
            ->when($offeringId > 0, fn ($query) => $query->where('class_section_offering_id', $offeringId))
            ->whereIn('enrollment_id', $enrollments->pluck('id')->filter())
            ->get(['enrollment_id', 'status']);

        $grouped = $records->groupBy('enrollment_id');
        $rows = $enrollments->values()->map(function (Enrollment $enrollment, int $index) use ($grouped) {
            $days = $grouped->get($enrollment->id, collect());
            $present = $days->filter(fn (AttendanceRecord $row) => $row->status === AttendanceStatus::Present)->count();
            $late = $days->filter(fn (AttendanceRecord $row) => $row->status === AttendanceStatus::Late)->count();
            $absent = $days->filter(fn (AttendanceRecord $row) => $row->status === AttendanceStatus::Absent)->count();
            $marked = $days->count();

            return [
                'index' => $index + 1,
                'admission_number' => $enrollment->student?->admission_number,
                'full_name' => $enrollment->student?->fullName(),
                'form' => $enrollment->classSectionOffering?->classSection?->name,
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'marked' => $marked,
                'percent' => $marked === 0 ? null : (int) round((($present + $late) / $marked) * 100),
            ];
        })->all();

        $marked = (int) collect($rows)->sum('marked');
        $in = (int) collect($rows)->sum('present') + (int) collect($rows)->sum('late');

        return $this->payload(
            'attendance',
            'Attendance · '.$form.' · '.$from.' to '.$to,
            $form,
            [
                ['key' => 'index', 'label' => '#'],
                ['key' => 'admission_number', 'label' => 'Admission'],
                ['key' => 'full_name', 'label' => 'Pupil'],
                ['key' => 'form', 'label' => 'Class'],
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
                'percent' => $marked === 0 ? null : (int) round(($in / $marked) * 100),
            ],
            $user,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function staffReport(User $user, array $filters): array
    {
        $from = (string) ($filters['from'] ?? Carbon::now('Africa/Lagos')->toDateString());
        $to = (string) ($filters['to'] ?? $from);

        $marks = AttendanceRecord::query()
            ->whereDate('marked_on', '>=', $from)
            ->whereDate('marked_on', '<=', $to)
            ->whereNotNull('marked_by')
            ->selectRaw('marked_by, COUNT(*) as marks')
            ->groupBy('marked_by')
            ->pluck('marks', 'marked_by');

        $rows = StaffProfile::query()
            ->with(['user', 'department'])
            ->orderBy('id')
            ->get()
            ->values()
            ->map(function (StaffProfile $staff, int $index) use ($marks) {
                $taken = (int) ($marks[$staff->user_id] ?? 0);

                return [
                    'index' => $index + 1,
                    'staff_number' => $staff->staff_number,
                    'full_name' => $staff->user?->name,
                    'job_title' => $staff->job_title,
                    'department' => $staff->department?->name,
                    'status' => $staff->status?->value,
                    'roll_taken' => $taken > 0 ? 'Yes' : 'No',
                    'marks' => $taken,
                ];
            })
            ->all();

        $present = collect($rows)->filter(fn (array $row) => $row['roll_taken'] === 'Yes')->count();

        return $this->payload(
            'staff',
            'Staff presence · '.$from.($from === $to ? '' : ' to '.$to),
            'Staff',
            [
                ['key' => 'index', 'label' => '#'],
                ['key' => 'staff_number', 'label' => 'Staff no.'],
                ['key' => 'full_name', 'label' => 'Name'],
                ['key' => 'job_title', 'label' => 'Post'],
                ['key' => 'department', 'label' => 'Department'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'roll_taken', 'label' => 'Took the roll'],
                ['key' => 'marks', 'label' => 'Marks posted'],
            ],
            $rows,
            [
                'from' => $from,
                'to' => $to,
                'staff' => count($rows),
                'present' => $present,
            ],
            $user,
        );
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function enrollments(?int $sessionId, ?int $offeringId)
    {
        return Enrollment::query()
            ->with(['student', 'classSectionOffering.classSection'])
            ->where('status', EnrollmentStatus::Active)
            ->when($sessionId, fn ($query) => $query->where('academic_session_id', $sessionId))
            ->when($offeringId, fn ($query) => $query->where('class_section_offering_id', $offeringId))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function sessionId(array $filters): ?int
    {
        if (! empty($filters['academic_session_id'])) {
            return (int) $filters['academic_session_id'];
        }

        $current = SchoolSetting::query()->value('current_academic_session_id');

        return $current ? (int) $current : null;
    }

    private function formLabel(int $offeringId, ?int $sessionId): string
    {
        if ($offeringId > 0) {
            return ClassSectionOffering::query()
                ->with('classSection')
                ->find($offeringId)
                ?->classSection
                ?->name ?: 'Form';
        }

        if ($sessionId) {
            return AcademicSession::query()->find($sessionId)?->name ?: 'Whole school';
        }

        return 'Whole school';
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
