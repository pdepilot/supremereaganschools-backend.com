<?php

namespace App\Services;

use App\Enums\AnnouncementCategory;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Announcement;
use App\Models\AssessmentScore;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\ClassSectionOffering;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\SchoolSetting;
use App\Models\TermResult;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StaffDeskService
{
    public function __construct(
        private readonly PeopleAccessService $access,
        private readonly AnnouncementService $announcements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        $user->loadMissing(['staffProfile.department']);

        $now = Carbon::now('Africa/Lagos');
        $today = $now->toDateString();
        $settings = SchoolSetting::query()->with(['currentAcademicSession', 'currentTerm'])->first();
        $staff = $user->staffProfile;
        $offeringIds = $this->access->assignedOfferingIds($user);
        $classTeacherIds = $this->access->classTeacherOfferingIds($user);

        $offerings = $offeringIds->isEmpty()
            ? collect()
            : ClassSectionOffering::query()
                ->with(['classSection', 'academicSession'])
                ->whereIn('id', $offeringIds)
                ->orderBy('id')
                ->get();

        $enrollments = $offeringIds->isEmpty()
            ? collect()
            : Enrollment::query()
                ->whereIn('class_section_offering_id', $offeringIds)
                ->where('status', EnrollmentStatus::Active)
                ->get(['id', 'student_profile_id', 'class_section_offering_id']);

        $todayRecords = $offeringIds->isEmpty()
            ? collect()
            : AttendanceRecord::query()
                ->whereIn('class_section_offering_id', $offeringIds)
                ->whereDate('marked_on', $today)
                ->get(['id', 'class_section_offering_id', 'status', 'enrollment_id']);

        $assignments = $offeringIds->isEmpty()
            ? collect()
            : Assignment::query()
                ->with(['subject', 'classSectionOffering.classSection'])
                ->whereIn('class_section_offering_id', $offeringIds)
                ->orderBy('due_on')
                ->orderBy('id')
                ->get();

        $fullName = trim($user->name) !== '' ? trim($user->name) : 'Faculty';
        $firstName = explode(' ', $fullName)[0];
        $pupils = $enrollments->pluck('student_profile_id')->unique()->count();
        $attendance = $this->attendancePulse($enrollments, $todayRecords);
        $openWork = $assignments->filter(
            fn (Assignment $row) => $row->due_on === null || $row->due_on->toDateString() >= $today,
        );
        $dueSoon = $openWork->filter(
            fn (Assignment $row) => $row->due_on !== null && $row->due_on->lte($now->copy()->endOfDay()->addDays(7)),
        );
        $average = $this->classAverage($enrollments, $settings?->current_term_id);
        $forms = $this->forms($offerings, $enrollments, $todayRecords, $assignments, $classTeacherIds, $today);
        $house = $this->featuredHouse($forms, $classTeacherIds);
        $letters = $this->unreadLetters($user);
        $schedule = $this->schedule($user, $offerings, $classTeacherIds, $now);
        $notices = $this->notices($user);
        $tasks = $this->tasks($offerings, $classTeacherIds, $todayRecords, $assignments, $schedule, $letters, $now);
        $dates = $this->dates($notices, $assignments, $now);

        return [
            'name' => $firstName,
            'full_name' => $fullName,
            'title' => $staff?->job_title ?: 'Faculty',
            'staff_number' => $staff?->staff_number,
            'department' => $staff?->department?->name,
            'initials' => $this->initials($fullName),
            'school' => $settings?->name ?: (string) config('app.name'),
            'session' => $settings?->currentAcademicSession?->name,
            'term' => $settings?->currentTerm?->name,
            'date_label' => $now->format('l, j F Y'),
            'drawn_at' => $now->toIso8601String(),
            'metrics' => [
                'pupils' => $pupils,
                'pupils_delta' => $pupils === 0
                    ? 'No form assigned yet'
                    : ($pupils === 1 ? '1 pupil on your forms' : $pupils.' pupils on your forms'),
                'attendance_percent' => $attendance['percent'],
                'attendance_delta' => $attendance['delta'],
                'assignments' => $openWork->count(),
                'assignments_delta' => $dueSoon->isEmpty()
                    ? ($openWork->isEmpty() ? 'No work on the board' : 'None due this week')
                    : ($dueSoon->count() === 1 ? '1 due this week' : $dueSoon->count().' due this week'),
                'average_percent' => $average['percent'],
                'average_delta' => $average['delta'],
                'letters' => $letters,
            ],
            'week' => $this->week($offeringIds, $now),
            'schedule' => $schedule,
            'tasks' => $tasks,
            'forms' => $forms,
            'house' => $house,
            'notices' => $notices,
            'dates' => $dates,
        ];
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array{percent: float|null, delta: string}
     */
    private function attendancePulse(Collection $enrollments, Collection $records): array
    {
        $expected = $enrollments->count();
        $marked = $records->count();

        if ($expected === 0) {
            return ['percent' => null, 'delta' => 'No roll to mark'];
        }

        if ($marked === 0) {
            return ['percent' => null, 'delta' => 'Roll not marked today'];
        }

        $in = $records->filter(
            fn (AttendanceRecord $record) => in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true),
        )->count();
        $percent = round(($in / $marked) * 100, 1);

        return [
            'percent' => $percent,
            'delta' => $marked < $expected
                ? $marked.' of '.$expected.' marked'
                : $in.' present of '.$expected,
        ];
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @return array{percent: float|null, delta: string}
     */
    private function classAverage(Collection $enrollments, ?int $termId): array
    {
        $ids = $enrollments->pluck('id');

        if ($ids->isEmpty() || $termId === null) {
            return ['percent' => null, 'delta' => $termId === null ? 'No term sealed' : 'No marks posted'];
        }

        $average = TermResult::query()
            ->whereIn('enrollment_id', $ids)
            ->where('term_id', $termId)
            ->avg('total');

        if ($average === null) {
            $average = AssessmentScore::query()
                ->whereIn('enrollment_id', $ids)
                ->where('term_id', $termId)
                ->avg('score');
        }

        if ($average === null) {
            return ['percent' => null, 'delta' => 'No marks posted'];
        }

        return [
            'percent' => round((float) $average, 1),
            'delta' => 'This term',
        ];
    }

    /**
     * @param  Collection<int, ClassSectionOffering>  $offerings
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  Collection<int, Assignment>  $assignments
     * @param  Collection<int, int>  $classTeacherIds
     * @return list<array<string, mixed>>
     */
    private function forms(
        Collection $offerings,
        Collection $enrollments,
        Collection $records,
        Collection $assignments,
        Collection $classTeacherIds,
        string $today,
    ): array {
        $byOffering = $enrollments->groupBy('class_section_offering_id');
        $recordsByOffering = $records->groupBy('class_section_offering_id');
        $workByOffering = $assignments->groupBy('class_section_offering_id');

        return $offerings->map(function (ClassSectionOffering $offering) use ($byOffering, $recordsByOffering, $workByOffering, $classTeacherIds, $today) {
            $roll = $byOffering->get($offering->id, collect());
            $marked = $recordsByOffering->get($offering->id, collect());
            $present = $marked->filter(
                fn (AttendanceRecord $record) => in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true),
            )->count();
            $open = $workByOffering->get($offering->id, collect())->filter(
                fn (Assignment $row) => $row->due_on === null || $row->due_on->toDateString() >= $today,
            )->count();

            return [
                'id' => $offering->id,
                'name' => $offering->classSection?->name ?: 'Form',
                'session_name' => $offering->academicSession?->name,
                'is_class_teacher' => $classTeacherIds->contains($offering->id),
                'pupils' => $roll->count(),
                'present' => $present,
                'marked' => $marked->count(),
                'assignments' => $open,
            ];
        })->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $forms
     * @param  Collection<int, int>  $classTeacherIds
     * @return array<string, mixed>|null
     */
    private function featuredHouse(array $forms, Collection $classTeacherIds): ?array
    {
        if ($forms === []) {
            return null;
        }

        foreach ($forms as $form) {
            if ($classTeacherIds->contains($form['id'])) {
                return $form;
            }
        }

        return $forms[0];
    }

    /**
     * @param  Collection<int, ClassSectionOffering>  $offerings
     * @param  Collection<int, int>  $classTeacherIds
     * @return list<array<string, mixed>>
     */
    private function schedule(User $user, Collection $offerings, Collection $classTeacherIds, Carbon $now): array
    {
        if ($offerings->isEmpty()) {
            return [];
        }

        $staffId = $user->staffProfile?->id;
        $ids = $offerings->pluck('id');

        $slots = TimetableSlot::query()
            ->with(['subject', 'classSectionOffering.classSection'])
            ->whereIn('class_section_offering_id', $ids)
            ->where('day_of_week', $now->isoWeekday())
            ->orderBy('starts_at')
            ->get()
            ->filter(function (TimetableSlot $slot) use ($staffId, $classTeacherIds) {
                if ($classTeacherIds->contains($slot->class_section_offering_id)) {
                    return true;
                }

                return $staffId !== null && (int) $slot->staff_profile_id === (int) $staffId;
            })
            ->values()
            ->take(8);

        return $slots->map(function (TimetableSlot $slot) use ($now) {
            $start = $this->at($now, $slot->starts_at);
            $end = $this->at($now, $slot->ends_at);
            $subject = $slot->subject?->name ?: ($slot->label ?: 'Period');
            $form = $slot->classSectionOffering?->classSection?->name;

            if ($now->gte($end)) {
                $status = 'done';
            } elseif ($now->gte($start)) {
                $status = 'now';
            } elseif ($start->isAfter($now) && $now->diffInMinutes($start) <= 90) {
                $status = 'next';
            } else {
                $status = 'later';
            }

            return [
                'starts_at' => $start->format('H:i'),
                'ends_at' => $end->format('H:i'),
                'hour' => $start->format('g:i'),
                'meridiem' => $start->format('A'),
                'subject' => $subject,
                'form' => $form,
                'status' => $status,
                'status_label' => match ($status) {
                    'now' => 'In session',
                    'next' => 'Next',
                    'done' => 'Done',
                    default => 'Later',
                },
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, ClassSectionOffering>  $offerings
     * @param  Collection<int, int>  $classTeacherIds
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  Collection<int, Assignment>  $assignments
     * @param  list<array<string, mixed>>  $schedule
     * @return list<array<string, mixed>>
     */
    private function tasks(
        Collection $offerings,
        Collection $classTeacherIds,
        Collection $records,
        Collection $assignments,
        array $schedule,
        int $letters,
        Carbon $now,
    ): array {
        $tasks = [];
        $today = $now->toDateString();
        $markedOfferings = $records->pluck('class_section_offering_id')->unique();

        foreach ($offerings as $offering) {
            if (! $classTeacherIds->contains($offering->id) || $markedOfferings->contains($offering->id)) {
                continue;
            }

            $tasks[] = [
                'key' => 'roll-'.$offering->id,
                'title' => 'Take attendance',
                'detail' => ($offering->classSection?->name ?: 'Your form').' · today',
                'href' => '/staff/attendance',
                'done' => false,
            ];
        }

        foreach ($schedule as $period) {
            if (($period['status'] ?? '') === 'now') {
                $tasks[] = [
                    'key' => 'period-now',
                    'title' => $period['subject'].' is in session',
                    'detail' => collect([$period['form'], $period['starts_at'].'–'.$period['ends_at']])->filter()->implode(' · '),
                    'href' => '/staff/timetable',
                    'done' => false,
                ];
                break;
            }
        }

        if ($letters > 0) {
            $tasks[] = [
                'key' => 'letters',
                'title' => $letters === 1 ? 'One unread letter' : $letters.' unread letters',
                'detail' => 'Faculty inbox',
                'href' => '/staff/messages',
                'done' => false,
            ];
        }

        foreach ($assignments as $assignment) {
            if ($assignment->due_on === null) {
                continue;
            }

            $due = $assignment->due_on->toDateString();
            if ($due > $now->copy()->addDays(3)->toDateString()) {
                continue;
            }

            $overdue = $due < $today;
            $tasks[] = [
                'key' => 'work-'.$assignment->id,
                'title' => $overdue ? 'Overdue work' : 'Work due',
                'detail' => collect([
                    $assignment->title,
                    $assignment->classSectionOffering?->classSection?->name,
                    $overdue || $due !== $today
                        ? 'Due '.$assignment->due_on->format('j M')
                        : 'Due today',
                ])->filter()->implode(' · '),
                'href' => '/staff/assignments',
                'done' => false,
            ];

            if (count($tasks) >= 6) {
                break;
            }
        }

        if ($tasks === [] && $classTeacherIds->isNotEmpty() && $markedOfferings->isNotEmpty()) {
            $tasks[] = [
                'key' => 'roll-done',
                'title' => 'Roll is marked',
                'detail' => 'Today’s attendance is on the ledger',
                'href' => '/staff/attendance',
                'done' => true,
            ];
        }

        return array_slice($tasks, 0, 6);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notices(User $user): array
    {
        return $this->announcements->visibleTo($user)
            ->limit(4)
            ->get()
            ->map(function (Announcement $row) {
                $when = $row->published_at ?? $row->created_at;

                return [
                    'id' => $row->id,
                    'title' => $row->title,
                    'excerpt' => Str::limit(trim(strip_tags((string) $row->body)), 90),
                    'when' => $when?->timezone('Africa/Lagos')->format('j M'),
                    'category' => $row->category?->value,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $notices
     * @param  Collection<int, Assignment>  $assignments
     * @return list<array<string, mixed>>
     */
    private function dates(array $notices, Collection $assignments, Carbon $now): array
    {
        $items = [];

        foreach ($assignments as $assignment) {
            if ($assignment->due_on === null || $assignment->due_on->lt($now->copy()->startOfDay())) {
                continue;
            }
            if ($assignment->due_on->gt($now->copy()->addDays(21))) {
                continue;
            }

            $items[] = [
                'day' => $assignment->due_on->format('j'),
                'month' => $assignment->due_on->format('M'),
                'sort' => $assignment->due_on->toDateString(),
                'title' => $assignment->title,
                'detail' => collect([
                    $assignment->subject?->name,
                    $assignment->classSectionOffering?->classSection?->name,
                    'Due',
                ])->filter()->implode(' · '),
            ];
        }

        foreach ($notices as $notice) {
            if (($notice['category'] ?? '') !== AnnouncementCategory::Event->value) {
                continue;
            }

            $items[] = [
                'day' => $now->format('j'),
                'month' => $now->format('M'),
                'sort' => $now->toDateString(),
                'title' => $notice['title'],
                'detail' => $notice['excerpt'] ?: 'School notice',
            ];
        }

        usort($items, fn (array $left, array $right) => strcmp((string) $left['sort'], (string) $right['sort']));

        return array_map(function (array $item) {
            unset($item['sort']);

            return $item;
        }, array_slice($items, 0, 4));
    }

    /**
     * @param  Collection<int, int>  $offeringIds
     * @return list<array<string, mixed>>
     */
    private function week(Collection $offeringIds, Carbon $now): array
    {
        $start = $now->copy()->startOfWeek();
        $end = $now->copy()->endOfWeek();
        $records = $offeringIds->isEmpty()
            ? collect()
            : AttendanceRecord::query()
                ->whereIn('class_section_offering_id', $offeringIds)
                ->whereBetween('marked_on', [$start->toDateString(), $end->toDateString()])
                ->get(['status', 'marked_on']);

        $byDay = $records->groupBy(fn (AttendanceRecord $record) => $record->marked_on?->toDateString());
        $days = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $rows = $byDay->get($key, collect());
            $total = $rows->count();
            $in = $rows->filter(
                fn (AttendanceRecord $record) => in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true),
            )->count();
            $future = $cursor->isAfter($now->copy()->startOfDay());

            $days[] = [
                'date' => $key,
                'label' => $cursor->format('D'),
                'percent' => $total === 0 ? null : round(($in / $total) * 100),
                'future' => $future,
                'today' => $cursor->isSameDay($now),
            ];
        }

        return $days;
    }

    private function unreadLetters(User $user): int
    {
        return (int) Message::query()
            ->join('conversation_participants as cp', function ($join) use ($user) {
                $join->on('cp.conversation_id', '=', 'messages.conversation_id')
                    ->where('cp.user_id', $user->id);
            })
            ->where('messages.sender_id', '!=', $user->id)
            ->where(function ($query) {
                $query->whereNull('cp.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'cp.last_read_at');
            })
            ->count();
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));

        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1).substr($parts[array_key_last($parts)], 0, 1));
        }

        return strtoupper(substr($name !== '' ? $name : 'F', 0, 2));
    }

    private function at(Carbon $day, mixed $time): Carbon
    {
        $stamp = substr((string) $time, 0, 8);
        if (strlen($stamp) === 5) {
            $stamp .= ':00';
        }

        return Carbon::parse($day->toDateString().' '.$stamp, $day->timezone);
    }
}
