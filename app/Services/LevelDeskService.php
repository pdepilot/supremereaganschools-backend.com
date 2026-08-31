<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SchoolWing;
use App\Enums\StudentStatus;
use App\Models\AttendanceRecord;
use App\Models\ClassSectionOffering;
use App\Models\Invoice;
use App\Models\SchoolSetting;
use App\Models\StudentProfile;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class LevelDeskService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(SchoolWing $wing): array
    {
        $settings = SchoolSetting::query()->with(['currentAcademicSession', 'currentTerm'])->first();
        $sessionId = $settings?->current_academic_session_id;
        $slugs = $wing->levelSlugs();

        $pupils = StudentProfile::query()
            ->where('status', StudentStatus::Active)
            ->where(fn (Builder $query) => $this->constrainToWing($query, $slugs))
            ->count();

        $forms = ClassSectionOffering::query()
            ->where('is_active', true)
            ->when($sessionId, fn (Builder $query) => $query->where('academic_session_id', $sessionId))
            ->whereHas(
                'classSection.schoolClass.level',
                fn (Builder $level) => $level->whereIn('slug', $slugs),
            )
            ->count();

        $studentIds = StudentProfile::query()
            ->where(fn (Builder $query) => $this->constrainToWing($query, $slugs))
            ->pluck('id');

        $outstandingKobo = 0;
        if ($studentIds->isNotEmpty()) {
            $outstandingKobo = (int) Invoice::query()
                ->whereIn('student_profile_id', $studentIds)
                ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Partial->value])
                ->when($sessionId, fn (Builder $query) => $query->where('academic_session_id', $sessionId))
                ->selectRaw('COALESCE(SUM(total_kobo - paid_kobo), 0) as remaining')
                ->value('remaining');
        }

        $outstanding = Money::compactNaira($outstandingKobo);
        $attendance = $this->attendancePulse($slugs);

        return [
            'slug' => $wing->value,
            'name' => $wing->label(),
            'copy' => $wing->copy(),
            'session' => $settings?->currentAcademicSession?->name,
            'term' => $settings?->currentTerm?->name,
            'metrics' => [
                'pupils' => $pupils,
                'pupils_delta' => 'Active on this desk',
                'forms' => $forms,
                'forms_delta' => $wing->label().' forms this session',
                'attendance_percent' => $attendance['percent'],
                'attendance_delta' => $attendance['delta'],
                'outstanding' => $outstanding['label'],
                'outstanding_delta' => 'Still due on this desk',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(
            fn (SchoolWing $wing) => $this->snapshot($wing),
            SchoolWing::cases(),
        );
    }

    /**
     * @param  list<string>  $slugs
     */
    private function constrainToWing(Builder $query, array $slugs): void
    {
        $query->whereHas('enrollments', function (Builder $enrollment) use ($slugs) {
            $enrollment->where('status', EnrollmentStatus::Active)
                ->whereHas(
                    'classSectionOffering.classSection.schoolClass.level',
                    fn (Builder $level) => $level->whereIn('slug', $slugs),
                );
        });
    }

    /**
     * @param  list<string>  $slugs
     * @return array{percent: float|null, delta: string}
     */
    private function attendancePulse(array $slugs): array
    {
        $latest = AttendanceRecord::query()
            ->whereHas(
                'enrollment.classSectionOffering.classSection.schoolClass.level',
                fn (Builder $level) => $level->whereIn('slug', $slugs),
            )
            ->max('marked_on');

        if ($latest === null) {
            return ['percent' => null, 'delta' => 'No roll marked yet'];
        }

        $when = Carbon::parse($latest);
        $records = AttendanceRecord::query()
            ->whereDate('marked_on', $when->toDateString())
            ->whereHas(
                'enrollment.classSectionOffering.classSection.schoolClass.level',
                fn (Builder $level) => $level->whereIn('slug', $slugs),
            )
            ->get();

        $total = $records->count();
        $in = $records->filter(
            fn (AttendanceRecord $record) => in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true),
        )->count();

        return [
            'percent' => $total > 0 ? round(($in / $total) * 100, 1) : null,
            'delta' => $when->timezone('Africa/Lagos')->toFormattedDateString(),
        ];
    }
}
