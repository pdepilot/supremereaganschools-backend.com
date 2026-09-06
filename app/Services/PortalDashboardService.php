<?php

namespace App\Services;

use App\Enums\AnnouncementStatus;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PermissionSlug;
use App\Enums\StaffStatus;
use App\Enums\StudentStatus;
use App\Models\AdmissionApplication;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\Campus;
use App\Models\ClassSectionOffering;
use App\Models\ContactEnquiry;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\Payment;
use App\Models\SchoolSetting;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PortalDashboardService
{
    public function __construct(
        private readonly InboxService $inbox,
        private readonly LevelDeskService $levelDesks,
        private readonly PeopleAccessService $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        $canPupils = $this->access->allows($user, PermissionSlug::StudentsView);
        $canStaff = $this->access->allows($user, PermissionSlug::StaffView);
        $canFees = $this->access->allows(
            $user,
            PermissionSlug::FeesView,
            PermissionSlug::FeesManage,
            PermissionSlug::PaymentsView,
            PermissionSlug::PaymentsManage,
        );
        $canForms = $this->access->allows($user, PermissionSlug::AcademicsView, PermissionSlug::AcademicsManage);
        $canAttendance = $this->access->allows($user, PermissionSlug::AttendanceView, PermissionSlug::AttendanceManage);
        $canNotices = $this->access->allows($user, PermissionSlug::NoticesView, PermissionSlug::NoticesManage);
        $canInbox = $this->access->allows(
            $user,
            PermissionSlug::ContactView,
            PermissionSlug::ContactManage,
            PermissionSlug::AdmissionsView,
            PermissionSlug::AdmissionsManage,
        );
        $canWings = $canPupils || $canForms;
        $canNews = $this->access->allows($user, PermissionSlug::NewsView, PermissionSlug::NewsManage);
        $canAdmissions = $this->access->allows($user, PermissionSlug::AdmissionsView, PermissionSlug::AdmissionsManage);
        $canContact = $this->access->allows($user, PermissionSlug::ContactView, PermissionSlug::ContactManage);
        $canAnalytics = $canPupils || $canFees || $canStaff || $canAdmissions || $canContact;

        $settings = SchoolSetting::query()->with(['currentAcademicSession', 'currentTerm'])->first();
        $sessionId = $settings?->current_academic_session_id;
        $sessionName = $settings?->currentAcademicSession?->name;
        $termName = $settings?->currentTerm?->name;
        $campus = Campus::query()->where('is_active', true)->orderBy('id')->value('name');
        $levels = Level::query()->where('is_active', true)->orderBy('sort_order')->get();

        $growth = $canAnalytics
            ? $this->growthAssay(
                canPupils: $canPupils,
                canFees: $canFees,
                canStaff: $canStaff,
                canAdmissions: $canAdmissions,
                canContact: $canContact,
                sessionId: $sessionId,
            )
            : null;

        $pupils = $canPupils
            ? StudentProfile::query()->where('status', StudentStatus::Active)->count()
            : null;
        $staff = $canStaff
            ? StaffProfile::query()->where('status', StaffStatus::Active)->count()
            : null;

        $forms = $canForms
            ? ClassSectionOffering::query()
                ->where('is_active', true)
                ->when($sessionId, fn ($query) => $query->where('academic_session_id', $sessionId))
                ->count()
            : null;

        $collected = ['count' => null, 'prefix' => '', 'suffix' => '', 'label' => null];
        $outstanding = ['label' => null];
        $collectionShare = null;

        if ($canFees) {
            $collectedKobo = (int) Payment::query()
                ->where('status', PaymentStatus::Posted)
                ->when($sessionId, fn ($query) => $query->whereHas(
                    'invoice',
                    fn ($invoice) => $invoice->where('academic_session_id', $sessionId),
                ))
                ->sum('amount_kobo');

            $invoicedKobo = (int) Invoice::query()
                ->where('status', '!=', InvoiceStatus::Void->value)
                ->when($sessionId, fn ($query) => $query->where('academic_session_id', $sessionId))
                ->sum('total_kobo');

            $outstandingKobo = (int) Invoice::query()
                ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Partial->value])
                ->when($sessionId, fn ($query) => $query->where('academic_session_id', $sessionId))
                ->selectRaw('COALESCE(SUM(total_kobo - paid_kobo), 0) as remaining')
                ->value('remaining');

            $collectionShare = $invoicedKobo > 0 ? (int) round(($collectedKobo / $invoicedKobo) * 100) : null;
            $collected = Money::compactNaira($collectedKobo);
            $outstanding = Money::compactNaira($outstandingKobo);
        }

        $attendance = $canAttendance
            ? $this->attendancePulse()
            : ['percent' => null, 'delta' => null];

        return [
            'name' => trim($user->name) !== '' ? trim($user->name) : 'Administrator',
            'school' => $settings?->name ?: (string) config('app.name'),
            'visibility' => [
                'pupils' => $canPupils,
                'staff' => $canStaff,
                'fees' => $canFees,
                'forms' => $canForms,
                'attendance' => $canAttendance,
                'tickets' => $canPupils || $canFees || $canNotices || $canStaff || $canAttendance,
                'inbox' => $canInbox,
                'lookup' => $canPupils,
                'house' => $canForms || $canFees,
                'wings' => $canWings,
                'news' => $canNews,
                'analytics' => $canAnalytics,
            ],
            'metrics' => [
                'attendance_percent' => $attendance['percent'],
                'attendance_delta' => $attendance['delta'],
                'pupils' => $pupils,
                'pupils_delta' => $canPupils
                    ? ($growth['cards']['pupils']['delta_label'] ?? 'Active on roll')
                    : null,
                'staff' => $staff,
                'staff_delta' => $canStaff
                    ? ($growth['cards']['staff']['delta_label']
                        ?? ($staff === 1 ? '1 active record' : $staff.' active records'))
                    : null,
                'fees_count' => $collected['count'],
                'fees_prefix' => $collected['prefix'],
                'fees_suffix' => $collected['suffix'],
                'fees_label' => $collected['label'],
                'fees_delta' => ! $canFees
                    ? null
                    : ($growth['cards']['fees']['delta_label']
                        ?? ($collectionShare === null
                            ? 'Posted collections'
                            : $collectionShare.'% of the ledger')),
                'forms' => $forms,
                'forms_delta' => $canForms
                    ? ($levels->pluck('name')->filter()->implode(' · ') ?: 'No levels sealed')
                    : null,
            ],
            'growth' => $growth,
            'house' => [
                'copy' => ($canForms || $canFees)
                    ? (collect([$sessionName, $termName, $campus])->filter()->implode(' · ') ?: 'No session sealed yet')
                    : null,
                'session' => $canForms ? ($sessionName ? $this->shortSession($sessionName) : '—') : null,
                'term' => $canForms ? ($termName ?: '—') : null,
                'levels' => $canForms ? $this->numberWord($levels->count()) : null,
                'outstanding' => $canFees ? $outstanding['label'] : null,
            ],
            'tickets' => $this->tickets($canPupils, $canFees, $canNotices, $canStaff, $canAttendance),
            'inbox' => $canInbox ? $this->inboxItems() : [],
            'wings' => $canWings ? $this->levelDesks->all() : [],
        ];
    }

    /**
     * @return array{percent: float|null, delta: string}
     */
    private function attendancePulse(): array
    {
        [$when, $records] = $this->latestRoll();

        if ($when === null) {
            return ['percent' => null, 'delta' => 'No roll marked yet'];
        }

        $total = $records->count();
        $in = $records->filter(
            fn (AttendanceRecord $record) => in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true),
        )->count();

        return [
            'percent' => $total > 0 ? round(($in / $total) * 100, 1) : null,
            'delta' => $when->timezone('Africa/Lagos')->toFormattedDateString(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tickets(
        bool $canPupils,
        bool $canFees,
        bool $canNotices,
        bool $canStaff,
        bool $canAttendance,
    ): array {
        $items = collect();

        if ($canPupils) {
            $items = $items->concat($this->pupilTickets());
        }
        if ($canFees) {
            $items = $items->concat($this->paymentTickets());
        }
        if ($canNotices) {
            $items = $items->concat($this->noticeTickets());
        }
        if ($canStaff) {
            $items = $items->concat($this->staffTickets());
        }
        if ($canAttendance) {
            $items = $items->concat($this->rollTickets());
        }

        return $items
            ->sortByDesc('at')
            ->take(5)
            ->values()
            ->map(fn (array $item) => collect($item)->except('at')->all())
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pupilTickets(): Collection
    {
        return StudentProfile::query()
            ->with(['enrollments' => fn ($query) => $query->where('status', EnrollmentStatus::Active)
                ->with('classSectionOffering.classSection')])
            ->latest('id')
            ->take(3)
            ->get()
            ->map(function (StudentProfile $student) {
                $form = $student->enrollments->first()?->classSectionOffering?->classSection?->name;
                $code = $student->admission_number ?: 'ADM';

                return [
                    'at' => $student->created_at?->timestamp ?? 0,
                    'code' => $code,
                    'title' => 'New pupil registered',
                    'detail' => trim($student->fullName().($form ? ' · '.$form : '')),
                    'badge' => 'Sealed',
                    'tone' => 'ok',
                    'text' => $code.' '.$student->fullName().($form ? ' sealed into '.$form : ' added to the roll'),
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function paymentTickets(): Collection
    {
        return Payment::query()
            ->with(['student', 'invoice.term'])
            ->where('status', PaymentStatus::Posted)
            ->latest('id')
            ->take(3)
            ->get()
            ->map(function (Payment $payment) {
                $code = $payment->reference ?: 'FEE';
                $term = $payment->invoice?->term?->name;

                return [
                    'at' => ($payment->paid_at ?? $payment->created_at)?->timestamp ?? 0,
                    'code' => $code,
                    'title' => 'Fee payment recorded',
                    'detail' => trim(Money::formatNaira((int) $payment->amount_kobo).($term ? ' · '.$term : '')),
                    'badge' => 'Posted',
                    'tone' => 'ok',
                    'text' => $code.' '.Money::formatNaira((int) $payment->amount_kobo).' received'.($term ? ' for '.$term : ''),
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function noticeTickets(): Collection
    {
        return Announcement::query()
            ->where('status', AnnouncementStatus::Published)
            ->latest('id')
            ->take(3)
            ->get()
            ->map(function (Announcement $notice) {
                $code = 'NOTE-'.str_pad((string) $notice->id, 3, '0', STR_PAD_LEFT);

                return [
                    'at' => ($notice->published_at ?? $notice->created_at)?->timestamp ?? 0,
                    'code' => $code,
                    'title' => $notice->title,
                    'detail' => 'Circular on the board',
                    'badge' => 'Live',
                    'tone' => 'warn',
                    'text' => $code.' '.$notice->title,
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function staffTickets(): Collection
    {
        return StaffProfile::query()
            ->with('user')
            ->latest('id')
            ->take(3)
            ->get()
            ->map(function (StaffProfile $staff) {
                $code = $staff->staff_number ?: 'STAFF';
                $name = $staff->user?->name ?: 'Staff member';

                return [
                    'at' => $staff->created_at?->timestamp ?? 0,
                    'code' => $code,
                    'title' => 'Master appointed',
                    'detail' => trim($name.($staff->job_title ? ' · '.$staff->job_title : '')),
                    'badge' => 'Cleared',
                    'tone' => 'ok',
                    'text' => $code.' '.$name.' joined the masters’ room',
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rollTickets(): Collection
    {
        [$when, $records] = $this->latestRoll();

        if ($when === null) {
            return collect();
        }

        $total = $records->count();
        $in = $records->filter(
            fn (AttendanceRecord $record) => in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true),
        )->count();
        $percent = $total > 0 ? round(($in / $total) * 100, 1) : 0;
        $when = $when->timezone('Africa/Lagos');

        return collect([[
            'at' => $when->timestamp,
            'code' => 'ROLL',
            'title' => 'Morning attendance',
            'detail' => $percent.'% marked in on '.$when->toFormattedDateString(),
            'badge' => 'Live',
            'tone' => 'ok',
            'text' => 'ROLL morning attendance closed at '.$percent.'%',
        ]]);
    }

    /**
     * @return array{0: Carbon|null, 1: Collection<int, AttendanceRecord>}
     */
    private function latestRoll(): array
    {
        $latest = AttendanceRecord::query()->max('marked_on');

        if ($latest === null) {
            return [null, collect()];
        }

        $when = Carbon::parse($latest);
        $records = AttendanceRecord::query()->whereDate('marked_on', $when->toDateString())->get();

        return [$when, $records];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inboxItems(): array
    {
        $chute = $this->inbox->chute();
        $rows = collect($chute['urgent'] ?? [])
            ->concat($chute['watch'] ?? [])
            ->take(3)
            ->values();

        return $rows->map(function (array $item) {
            $created = isset($item['created_at']) ? Carbon::parse($item['created_at']) : null;
            $unread = in_array($item['status'] ?? '', ['unread', 'urgent', 'submitted'], true);

            return [
                'name' => $item['name'] ?? 'Correspondent',
                'preview' => $item['preview'] ?? '',
                'meta' => trim(($created?->diffForHumans() ?? '').' · '.($unread ? 'unread' : 'read')),
                'unread' => $unread,
            ];
        })->all();
    }

    /**
     * Six-month growth assay for the command desk.
     *
     * @return array<string, mixed>
     */
    private function growthAssay(
        bool $canPupils,
        bool $canFees,
        bool $canStaff,
        bool $canAdmissions,
        bool $canContact,
        ?int $sessionId,
    ): array {
        $buckets = $this->monthBuckets(6);
        $rangeStart = $buckets->first()['from']->copy();
        $series = [];
        $cards = [];
        $insights = [];

        if ($canPupils) {
            $pupilPoints = StudentProfile::query()
                ->where(function ($query) use ($rangeStart) {
                    $query->whereDate('admitted_on', '>=', $rangeStart->toDateString())
                        ->orWhere(function ($inner) use ($rangeStart) {
                            $inner->whereNull('admitted_on')
                                ->where('created_at', '>=', $rangeStart);
                        });
                })
                ->get(['admitted_on', 'created_at'])
                ->map(fn (StudentProfile $student) => [
                    'at' => Carbon::parse($student->admitted_on ?? $student->created_at)->timezone('Africa/Lagos'),
                    'value' => 1,
                ]);

            $pupilsSeries = $this->bucketSeries($buckets, $pupilPoints);
            $pupilsCard = $this->seriesCard($pupilsSeries, 'pupils', false);
            $series[] = [
                'key' => 'pupils',
                'title' => 'Pupils admitted',
                'unit' => 'count',
                'points' => $pupilsSeries,
                'change_percent' => $pupilsCard['change_percent'],
                'change_label' => $pupilsCard['change_label'],
                'total' => $pupilsCard['total'],
                'total_label' => (string) $pupilsCard['total'],
            ];
            $cards['pupils'] = [
                'delta_label' => $pupilsCard['delta_label'],
                'change_percent' => $pupilsCard['change_percent'],
            ];
            if ($pupilsCard['insight'] !== null) {
                $insights[] = $pupilsCard['insight'];
            }
        }

        if ($canFees) {
            $feePoints = Payment::query()
                ->where('status', PaymentStatus::Posted)
                ->where('paid_at', '>=', $rangeStart)
                ->when($sessionId, fn ($query) => $query->whereHas(
                    'invoice',
                    fn ($invoice) => $invoice->where('academic_session_id', $sessionId),
                ))
                ->get(['paid_at', 'amount_kobo'])
                ->map(fn (Payment $payment) => [
                    'at' => Carbon::parse($payment->paid_at)->timezone('Africa/Lagos'),
                    'value' => (int) $payment->amount_kobo,
                ]);

            $feesSeries = $this->bucketSeries($buckets, $feePoints);
            $feesCard = $this->seriesCard($feesSeries, 'fees', true);
            $series[] = [
                'key' => 'fees',
                'title' => 'Fees collected',
                'unit' => 'money',
                'points' => collect($feesSeries)->map(function (array $point) {
                    $money = Money::compactNaira((int) $point['value']);

                    return [
                        'period' => $point['period'],
                        'label' => $point['label'],
                        'value' => $point['value'],
                        'display' => $money['label'],
                    ];
                })->all(),
                'change_percent' => $feesCard['change_percent'],
                'change_label' => $feesCard['change_label'],
                'total' => $feesCard['total'],
                'total_label' => Money::compactNaira((int) $feesCard['total'])['label'],
            ];
            $cards['fees'] = [
                'delta_label' => $feesCard['delta_label'],
                'change_percent' => $feesCard['change_percent'],
            ];
            if ($feesCard['insight'] !== null) {
                $insights[] = $feesCard['insight'];
            }
        }

        if ($canStaff) {
            $staffPoints = StaffProfile::query()
                ->where(function ($query) use ($rangeStart) {
                    $query->whereDate('employed_on', '>=', $rangeStart->toDateString())
                        ->orWhere(function ($inner) use ($rangeStart) {
                            $inner->whereNull('employed_on')
                                ->where('created_at', '>=', $rangeStart);
                        });
                })
                ->get(['employed_on', 'created_at'])
                ->map(fn (StaffProfile $staff) => [
                    'at' => Carbon::parse($staff->employed_on ?? $staff->created_at)->timezone('Africa/Lagos'),
                    'value' => 1,
                ]);

            $staffSeries = $this->bucketSeries($buckets, $staffPoints);
            $staffCard = $this->seriesCard($staffSeries, 'staff', false);
            $series[] = [
                'key' => 'staff',
                'title' => 'Staff appointed',
                'unit' => 'count',
                'points' => $staffSeries,
                'change_percent' => $staffCard['change_percent'],
                'change_label' => $staffCard['change_label'],
                'total' => $staffCard['total'],
                'total_label' => (string) $staffCard['total'],
            ];
            $cards['staff'] = [
                'delta_label' => $staffCard['delta_label'],
                'change_percent' => $staffCard['change_percent'],
            ];
            if ($staffCard['insight'] !== null) {
                $insights[] = $staffCard['insight'];
            }
        }

        if ($canAdmissions) {
            $admissionPoints = AdmissionApplication::query()
                ->where('created_at', '>=', $rangeStart)
                ->get(['created_at'])
                ->map(fn (AdmissionApplication $row) => [
                    'at' => Carbon::parse($row->created_at)->timezone('Africa/Lagos'),
                    'value' => 1,
                ]);

            $admissionsSeries = $this->bucketSeries($buckets, $admissionPoints);
            $admissionsCard = $this->seriesCard($admissionsSeries, 'admissions', false);
            $series[] = [
                'key' => 'admissions',
                'title' => 'Admission applications',
                'unit' => 'count',
                'points' => $admissionsSeries,
                'change_percent' => $admissionsCard['change_percent'],
                'change_label' => $admissionsCard['change_label'],
                'total' => $admissionsCard['total'],
                'total_label' => (string) $admissionsCard['total'],
            ];
            if ($admissionsCard['insight'] !== null) {
                $insights[] = $admissionsCard['insight'];
            }
        }

        if ($canContact) {
            $enquiryPoints = ContactEnquiry::query()
                ->where('created_at', '>=', $rangeStart)
                ->get(['created_at'])
                ->map(fn (ContactEnquiry $row) => [
                    'at' => Carbon::parse($row->created_at)->timezone('Africa/Lagos'),
                    'value' => 1,
                ]);

            $enquiriesSeries = $this->bucketSeries($buckets, $enquiryPoints);
            $enquiriesCard = $this->seriesCard($enquiriesSeries, 'enquiries', false);
            $series[] = [
                'key' => 'enquiries',
                'title' => 'Contact enquiries',
                'unit' => 'count',
                'points' => $enquiriesSeries,
                'change_percent' => $enquiriesCard['change_percent'],
                'change_label' => $enquiriesCard['change_label'],
                'total' => $enquiriesCard['total'],
                'total_label' => (string) $enquiriesCard['total'],
            ];
            if ($enquiriesCard['insight'] !== null) {
                $insights[] = $enquiriesCard['insight'];
            }
        }

        return [
            'months' => $buckets->count(),
            'from' => $rangeStart->toDateString(),
            'to' => now('Africa/Lagos')->toDateString(),
            'copy' => 'Six-month growth across the house ledger.',
            'series' => $series,
            'cards' => $cards,
            'insights' => array_slice($insights, 0, 4),
        ];
    }

    /**
     * @return Collection<int, array{period: string, label: string, from: Carbon, to: Carbon}>
     */
    private function monthBuckets(int $months = 6): Collection
    {
        $cursor = now('Africa/Lagos')->startOfMonth();

        return collect(range($months - 1, 0))->map(function (int $offset) use ($cursor) {
            $start = $cursor->copy()->subMonths($offset);

            return [
                'period' => $start->format('Y-m'),
                'label' => $start->format('M'),
                'from' => $start->copy()->startOfMonth(),
                'to' => $start->copy()->endOfMonth(),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array{period: string, label: string, from: Carbon, to: Carbon}>  $buckets
     * @param  Collection<int, array{at: Carbon, value: int}>  $points
     * @return list<array{period: string, label: string, value: int}>
     */
    private function bucketSeries(Collection $buckets, Collection $points): array
    {
        $totals = [];

        foreach ($points as $point) {
            $key = $point['at']->format('Y-m');
            $totals[$key] = ($totals[$key] ?? 0) + (int) $point['value'];
        }

        return $buckets->map(fn (array $bucket) => [
            'period' => $bucket['period'],
            'label' => $bucket['label'],
            'value' => (int) ($totals[$bucket['period']] ?? 0),
        ])->all();
    }

    /**
     * @param  list<array{period: string, label: string, value: int}>  $series
     * @return array{
     *     total: int,
     *     change_percent: int|null,
     *     change_label: string,
     *     delta_label: string,
     *     insight: array{tone: string, text: string}|null
     * }
     */
    private function seriesCard(array $series, string $key, bool $money): array
    {
        $values = array_column($series, 'value');
        $total = (int) array_sum($values);
        $current = (int) ($values[count($values) - 1] ?? 0);
        $previous = (int) ($values[count($values) - 2] ?? 0);

        $changePercent = null;
        if ($previous > 0) {
            $changePercent = (int) round((($current - $previous) / $previous) * 100);
        } elseif ($current > 0) {
            $changePercent = 100;
        } elseif ($current === 0 && $previous === 0) {
            $changePercent = 0;
        }

        $changeLabel = match (true) {
            $changePercent === null => 'No prior month',
            $changePercent > 0 => '+'.$changePercent.'% vs last month',
            $changePercent < 0 => $changePercent.'% vs last month',
            default => 'Flat vs last month',
        };

        $currentLabel = $money
            ? Money::compactNaira($current)['label']
            : (string) $current;

        $deltaLabel = match ($key) {
            'pupils' => $current === 0
                ? 'No admissions this month'
                : ($current === 1 ? '1 admitted this month' : $current.' admitted this month'),
            'fees' => $current === 0
                ? 'No collections this month'
                : $currentLabel.' this month',
            'staff' => $current === 0
                ? 'No appointments this month'
                : ($current === 1 ? '1 appointed this month' : $current.' appointed this month'),
            default => $changeLabel,
        };

        $noun = match ($key) {
            'pupils' => 'Pupil admissions',
            'fees' => 'Fee collections',
            'staff' => 'Staff appointments',
            'admissions' => 'Admission applications',
            'enquiries' => 'Contact enquiries',
            default => 'Activity',
        };

        $insight = null;
        if ($changePercent !== null && ($current > 0 || $previous > 0)) {
            $tone = $changePercent >= 0 ? 'ok' : 'warn';
            $insight = [
                'tone' => $tone,
                'text' => $noun.' '.$changeLabel.'.',
            ];
        }

        return [
            'total' => $total,
            'change_percent' => $changePercent,
            'change_label' => $changeLabel,
            'delta_label' => $deltaLabel,
            'insight' => $insight,
        ];
    }

    private function shortSession(string $name): string
    {
        return (string) preg_replace('/^20(\d{2})\/20(\d{2})$/', '$1/$2', $name);
    }

    private function numberWord(int $number): string
    {
        return [
            0 => 'None',
            1 => 'One',
            2 => 'Two',
            3 => 'Three',
            4 => 'Four',
            5 => 'Five',
        ][$number] ?? (string) $number;
    }
}
