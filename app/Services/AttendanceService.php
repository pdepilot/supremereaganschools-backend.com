<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\ClassSectionOffering;
use App\Models\Enrollment;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(private readonly PeopleAccessService $access) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function mark(array $attributes, User $actor): AttendanceRecord
    {
        $enrollment = $this->enrollmentForMarking($attributes['enrollment_id'] ?? null);
        $date = (string) $attributes['marked_on'];

        $this->assertMayMark($actor, $enrollment->class_section_offering_id);
        $this->assertDateIsAllowed($enrollment, $date);

        if ($this->existingFor($enrollment->id, $date)) {
            throw ValidationException::withMessages([
                'marked_on' => 'Attendance for this pupil has already been recorded on that date.',
            ]);
        }

        return AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $enrollment->class_section_offering_id,
            'marked_on' => $date,
            'status' => $attributes['status'],
            'remark' => $attributes['remark'] ?? null,
            'marked_by' => $actor->id,
        ])->load($this->defaultRelations());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AttendanceRecord $record, array $attributes, User $actor): AttendanceRecord
    {
        $this->assertMayMark($actor, $record->class_section_offering_id);

        $status = AttendanceStatus::from($attributes['status'] ?? $record->status->value);
        $remark = array_key_exists('remark', $attributes) ? $attributes['remark'] : $record->remark;
        $statusChanged = $status !== $record->status;
        $remarkChanged = $remark !== $record->remark;

        if (! $statusChanged && ! $remarkChanged) {
            return $record->load($this->defaultRelations());
        }

        if ($statusChanged && blank($attributes['correction_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'correction_reason' => 'A reason is required when attendance is corrected.',
            ]);
        }

        return DB::transaction(function () use ($record, $actor, $status, $remark, $attributes) {
            AttendanceCorrection::query()->create([
                'attendance_record_id' => $record->id,
                'from_status' => $record->status->value,
                'to_status' => $status->value,
                'from_remark' => $record->remark,
                'to_remark' => $remark,
                'reason' => $attributes['correction_reason'] ?? 'Remark updated.',
                'corrected_by' => $actor->id,
            ]);

            $record->update([
                'status' => $status,
                'remark' => $remark,
            ]);

            return $record->fresh($this->defaultRelations());
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, AttendanceRecord>
     */
    public function bulk(array $payload, User $actor): Collection
    {
        $offering = ClassSectionOffering::query()->find($payload['class_section_offering_id'] ?? null);

        if ($offering === null) {
            throw ValidationException::withMessages([
                'class_section_offering_id' => 'The selected class offering does not exist.',
            ]);
        }

        $this->assertMayMark($actor, $offering->id);

        $date = (string) $payload['marked_on'];
        $rows = $payload['records'] ?? [];

        return DB::transaction(function () use ($rows, $offering, $date, $actor, $payload) {
            $saved = collect();

            foreach ($rows as $index => $row) {
                $enrollment = $this->enrollmentForMarking($row['enrollment_id'] ?? null);

                if ((int) $enrollment->class_section_offering_id !== (int) $offering->id) {
                    throw ValidationException::withMessages([
                        "records.$index.enrollment_id" => 'This pupil is not enrolled in the selected class.',
                    ]);
                }

                $this->assertDateIsAllowed($enrollment, $date);

                $existing = $this->existingFor($enrollment->id, $date);
                $status = AttendanceStatus::from($row['status']);
                $remark = $row['remark'] ?? null;

                if ($existing === null) {
                    $saved->push(AttendanceRecord::query()->create([
                        'enrollment_id' => $enrollment->id,
                        'class_section_offering_id' => $offering->id,
                        'marked_on' => $date,
                        'status' => $status,
                        'remark' => $remark,
                        'marked_by' => $actor->id,
                    ]));

                    continue;
                }

                if ($existing->status === $status && $existing->remark === $remark) {
                    $saved->push($existing);

                    continue;
                }

                if ($existing->status !== $status && blank($payload['correction_reason'] ?? null)) {
                    throw ValidationException::withMessages([
                        'correction_reason' => 'A reason is required when existing attendance is changed.',
                    ]);
                }

                AttendanceCorrection::query()->create([
                    'attendance_record_id' => $existing->id,
                    'from_status' => $existing->status->value,
                    'to_status' => $status->value,
                    'from_remark' => $existing->remark,
                    'to_remark' => $remark,
                    'reason' => $payload['correction_reason'] ?? 'Updated from the class register.',
                    'corrected_by' => $actor->id,
                ]);

                $existing->update([
                    'status' => $status,
                    'remark' => $remark,
                ]);

                $saved->push($existing->fresh());
            }

            return AttendanceRecord::query()
                ->with($this->defaultRelations())
                ->whereIn('id', $saved->pluck('id'))
                ->orderBy('id')
                ->get();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function register(int $offeringId, string $date, User $actor): array
    {
        if (! $this->access->canViewAttendanceForOffering($actor, $offeringId)
            && ! $this->access->canMarkAttendanceForOffering($actor, $offeringId)) {
            throw new AuthorizationException;
        }

        $offering = ClassSectionOffering::query()
            ->with(['classSection', 'academicSession'])
            ->find($offeringId);

        if ($offering === null) {
            throw ValidationException::withMessages([
                'class_section_offering_id' => 'The selected class offering does not exist.',
            ]);
        }

        $enrollments = $this->enrollmentsOnDate($offeringId, $date);

        $records = AttendanceRecord::query()
            ->with($this->defaultRelations())
            ->where('class_section_offering_id', $offeringId)
            ->whereDate('marked_on', $date)
            ->get()
            ->keyBy('enrollment_id');

        $roll = $enrollments->map(function (Enrollment $enrollment) use ($records) {
            return [
                'enrollment_id' => $enrollment->id,
                'student_profile_id' => $enrollment->student_profile_id,
                'admission_number' => $enrollment->student?->admission_number,
                'full_name' => $enrollment->student?->fullName(),
                'attendance' => $records->get($enrollment->id),
            ];
        })->values();

        return [
            'class_section_offering_id' => $offering->id,
            'form' => $offering->classSection?->name,
            'session_name' => $offering->academicSession?->name,
            'marked_on' => $date,
            'can_mark' => $this->access->canMarkAttendanceForOffering($actor, $offeringId),
            'summary' => $this->summarize($records->values(), $enrollments->count()),
            'students' => $roll,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function studentSummary(?int $studentId, ?int $enrollmentId, ?string $from, ?string $to, ?int $termId, User $actor): array
    {
        $query = AttendanceRecord::query()->with($this->defaultRelations());

        if ($enrollmentId) {
            $enrollment = Enrollment::query()->with('student')->findOrFail($enrollmentId);
            $this->assertMayViewStudent($actor, $enrollment->student_profile_id);
            $query->where('enrollment_id', $enrollment->id);
        } elseif ($studentId) {
            $this->assertMayViewStudent($actor, $studentId);
            $query->whereHas('enrollment', fn ($enrollment) => $enrollment->where('student_profile_id', $studentId));
        } elseif ($this->access->isStudent($actor)) {
            $query->whereHas('enrollment', fn ($enrollment) => $enrollment->where('student_profile_id', $actor->studentProfile?->id));
        } else {
            throw ValidationException::withMessages([
                'student_profile_id' => 'A pupil or enrollment is required.',
            ]);
        }

        $this->applyRange($query, $from, $to, $termId);

        $records = $query->orderBy('marked_on')->get();

        return [
            'summary' => $this->summarize($records, $records->count()),
            'records' => $records,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function classSummary(int $offeringId, string $date, User $actor): array
    {
        if (! $this->access->canViewAttendanceForOffering($actor, $offeringId)
            && ! $this->access->administers($actor)) {
            throw new AuthorizationException;
        }

        $onRoll = $this->enrollmentsOnDate($offeringId, $date)->count();
        $records = AttendanceRecord::query()
            ->where('class_section_offering_id', $offeringId)
            ->whereDate('marked_on', $date)
            ->get();

        return $this->summarize($records, $onRoll);
    }

    public function delete(AttendanceRecord $record, User $actor): void
    {
        if (! $this->access->administers($actor)) {
            throw new AuthorizationException;
        }

        if ($record->corrections()->exists()) {
            throw ValidationException::withMessages([
                'attendance' => 'This mark has a correction history and cannot be deleted. Correct it instead.',
            ]);
        }

        $record->delete();
    }

    /**
     * @return list<string>
     */
    public function defaultRelations(): array
    {
        return [
            'enrollment.student',
            'classSectionOffering.classSection',
            'marker:id,name',
            'corrections.corrector:id,name',
        ];
    }

    private function enrollmentForMarking(mixed $id): Enrollment
    {
        $enrollment = Enrollment::query()->with(['academicSession', 'student'])->find($id);

        if ($enrollment === null) {
            throw ValidationException::withMessages([
                'enrollment_id' => 'The selected enrollment does not exist.',
            ]);
        }

        return $enrollment;
    }

    private function existingFor(int $enrollmentId, string $date): ?AttendanceRecord
    {
        return AttendanceRecord::query()
            ->where('enrollment_id', $enrollmentId)
            ->whereDate('marked_on', $date)
            ->first();
    }

    private function enrollmentsOnDate(int $offeringId, string $date): Collection
    {
        return Enrollment::query()
            ->with('student')
            ->where('class_section_offering_id', $offeringId)
            ->whereDate('enrolled_on', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('left_on')->orWhereDate('left_on', '>=', $date);
            })
            ->orderBy('id')
            ->get();
    }

    private function assertMayMark(User $actor, int $offeringId): void
    {
        if (! $this->access->canMarkAttendanceForOffering($actor, $offeringId)) {
            throw new AuthorizationException;
        }
    }

    private function assertMayViewStudent(User $actor, int $studentId): void
    {
        $student = \App\Models\StudentProfile::query()->find($studentId);

        if ($student === null || ! $this->access->canViewStudent($actor, $student)) {
            throw new AuthorizationException;
        }
    }

    private function assertDateIsAllowed(Enrollment $enrollment, string $date): void
    {
        $today = Carbon::now('Africa/Lagos')->toDateString();

        if ($date > $today) {
            throw ValidationException::withMessages([
                'marked_on' => 'Attendance cannot be recorded for a future date.',
            ]);
        }

        $session = $enrollment->academicSession;

        if ($session?->starts_on && $date < $session->starts_on->toDateString()) {
            throw ValidationException::withMessages([
                'marked_on' => 'That date is before the academic session starts.',
            ]);
        }

        if ($session?->ends_on && $date > $session->ends_on->toDateString()) {
            throw ValidationException::withMessages([
                'marked_on' => 'That date is after the academic session ends.',
            ]);
        }

        if ($enrollment->enrolled_on && $date < $enrollment->enrolled_on->toDateString()) {
            throw ValidationException::withMessages([
                'marked_on' => 'Attendance cannot be recorded before this pupil was enrolled.',
            ]);
        }

        if ($enrollment->left_on && $date > $enrollment->left_on->toDateString()) {
            throw ValidationException::withMessages([
                'marked_on' => 'Attendance cannot be recorded after this pupil left the form.',
            ]);
        }
    }

    private function applyRange($query, ?string $from, ?string $to, ?int $termId): void
    {
        if ($termId) {
            $term = Term::query()->find($termId);

            if ($term === null) {
                throw ValidationException::withMessages([
                    'term_id' => 'The selected term does not exist.',
                ]);
            }

            if ($term->starts_on && $term->ends_on) {
                $query->whereDate('marked_on', '>=', $term->starts_on->toDateString())
                    ->whereDate('marked_on', '<=', $term->ends_on->toDateString());
            } else {
                $query->whereHas('enrollment', fn ($enrollment) => $enrollment->where('academic_session_id', $term->academic_session_id));
            }
        }

        if ($from) {
            $query->whereDate('marked_on', '>=', $from);
        }

        if ($to) {
            $query->whereDate('marked_on', '<=', $to);
        }
    }

    /**
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array<string, mixed>
     */
    private function summarize(Collection $records, int $total): array
    {
        $present = $records->filter(fn (AttendanceRecord $record) => $record->status === AttendanceStatus::Present)->count();
        $absent = $records->filter(fn (AttendanceRecord $record) => $record->status === AttendanceStatus::Absent)->count();
        $late = $records->filter(fn (AttendanceRecord $record) => $record->status === AttendanceStatus::Late)->count();
        $recorded = $records->count();
        $attended = $present + $late;
        $base = $total > 0 ? $total : $recorded;
        $percentage = $base > 0 ? round(($attended / $base) * 100, 1) : 0.0;

        return [
            'total' => $base,
            'recorded' => $recorded,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'percentage' => $percentage,
        ];
    }
}
