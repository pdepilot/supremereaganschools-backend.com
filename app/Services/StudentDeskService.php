<?php

namespace App\Services;

use App\Enums\AnnouncementCategory;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\ClassTeacherAssignment;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\SchoolSetting;
use App\Models\StudentProfile;
use App\Models\TermResult;
use App\Models\TermSummary;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StudentDeskService
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        $user->loadMissing('studentProfile');

        $now = Carbon::now('Africa/Lagos');
        $today = $now->toDateString();
        $settings = SchoolSetting::query()->with(['currentAcademicSession', 'currentTerm'])->first();
        $profile = $user->studentProfile;
        $enrollment = $this->currentEnrollment($profile);
        $offering = $enrollment?->classSectionOffering;
        $offeringId = $offering?->id;

        $fullName = $profile?->fullName() ?: (trim($user->name) !== '' ? trim($user->name) : 'Pupil');
        $firstName = $profile?->first_name ?: (explode(' ', $fullName)[0] ?? 'Pupil');

        $assignments = $offeringId
            ? Assignment::query()
                ->with(['subject', 'staff.user'])
                ->where('class_section_offering_id', $offeringId)
                ->orderBy('due_on')
                ->orderBy('id')
                ->get()
            : collect();

        $attendance = $this->attendancePulse($enrollment, $settings?->current_academic_session_id);
        $average = $this->academicPulse($enrollment, $settings?->current_term_id);
        $openWork = $assignments->filter(
            fn (Assignment $row) => $row->due_on === null || $row->due_on->toDateString() >= $today,
        );
        $dueSoon = $openWork->filter(
            fn (Assignment $row) => $row->due_on !== null && $row->due_on->lte($now->copy()->endOfDay()->addDays(7)),
        );

        return [
            'id' => $profile?->id,
            'name' => $firstName,
            'full_name' => $fullName,
            'initials' => $this->initials($fullName),
            'admission_number' => $profile?->admission_number,
            'photo_url' => filled($profile?->photo_path) ? '/api/v1/students/'.$profile->id.'/photo' : null,
            'form' => $offering?->classSection?->name,
            'campus' => $offering?->campus?->name,
            'school' => $settings?->name ?: (string) config('app.name'),
            'school_short' => $this->brandName($settings),
            'logo_path' => $settings?->logo_path ?: '/site/Image/logo_main.png',
            'class_teacher' => $this->classTeacherName($offeringId),
            'session' => $settings?->currentAcademicSession?->name ?: $enrollment?->academicSession?->name,
            'term' => $settings?->currentTerm?->name,
            'date_label' => $now->format('l, j F'),
            'drawn_at' => $now->toIso8601String(),
            'metrics' => [
                'average_percent' => $average['percent'],
                'average_delta' => $average['delta'],
                'attendance_percent' => $attendance['percent'],
                'attendance_delta' => $attendance['delta'],
                'class_position' => $average['position'],
                'class_position_label' => $average['position'] !== null ? $this->ordinal($average['position']) : null,
                'class_size' => $average['size'],
                'position_delta' => $average['position_delta'],
                'assignments' => $openWork->count(),
                'assignments_delta' => $dueSoon->isEmpty()
                    ? ($openWork->isEmpty() ? 'No work on the board' : 'None due this week')
                    : ($dueSoon->count() === 1 ? 'Due this week' : $dueSoon->count().' due this week'),
                'letters' => $this->unreadLetters($user),
            ],
            'schedule' => $this->schedule($offeringId, $now),
            'assignments' => $this->assignmentRows($assignments, $now),
            'notices' => $this->notices($user, $now),
        ];
    }

    private function currentEnrollment(?StudentProfile $profile): ?Enrollment
    {
        if ($profile === null) {
            return null;
        }

        return Enrollment::query()
            ->with([
                'academicSession',
                'classSectionOffering.classSection',
                'classSectionOffering.campus',
            ])
            ->where('student_profile_id', $profile->id)
            ->where('status', EnrollmentStatus::Active)
            ->orderByDesc('enrolled_on')
            ->first();
    }

    private function classTeacherName(?int $offeringId): ?string
    {
        if ($offeringId === null) {
            return null;
        }

        $row = ClassTeacherAssignment::query()
            ->with('staff.user')
            ->where('class_section_offering_id', $offeringId)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        $name = trim((string) ($row?->staff?->user?->name ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * @return array{percent: float|null, delta: string}
     */
    private function attendancePulse(?Enrollment $enrollment, ?int $sessionId): array
    {
        if ($enrollment === null) {
            return ['percent' => null, 'delta' => 'No class assigned yet'];
        }

        $query = AttendanceRecord::query()->where('enrollment_id', $enrollment->id);
        if ($sessionId) {
            $query->whereHas(
                'enrollment',
                fn ($row) => $row->where('academic_session_id', $sessionId),
            );
        }

        $records = $query->get(['status']);
        if ($records->isEmpty()) {
            return ['percent' => null, 'delta' => 'No roll marked yet'];
        }

        $in = $records->filter(
            fn (AttendanceRecord $record) => in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true),
        )->count();
        $percent = round(($in / $records->count()) * 100, 1);

        return [
            'percent' => $percent,
            'delta' => $this->attendanceCopy($percent),
        ];
    }

    /**
     * @return array{percent: float|null, delta: string, position: int|null, size: int|null, position_delta: string}
     */
    private function academicPulse(?Enrollment $enrollment, ?int $termId): array
    {
        $empty = [
            'percent' => null,
            'delta' => $termId === null ? 'No term sealed' : 'No marks posted',
            'position' => null,
            'size' => null,
            'position_delta' => 'No position yet',
        ];

        if ($enrollment === null || $termId === null) {
            return $empty;
        }

        $summary = TermSummary::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('term_id', $termId)
            ->first();

        $percent = $summary?->average !== null
            ? round((float) $summary->average, 1)
            : null;

        if ($percent === null) {
            $average = TermResult::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('term_id', $termId)
                ->avg('total');
            $percent = $average === null ? null : round((float) $average, 1);
        }

        if ($percent === null) {
            return $empty;
        }

        $position = $summary?->class_position;
        $size = $summary?->class_size;

        return [
            'percent' => $percent,
            'delta' => $this->averageCopy($percent),
            'position' => $position,
            'size' => $size,
            'position_delta' => $position !== null && $size
                ? 'Out of '.$size.' '.($size === 1 ? 'student' : 'students')
                : 'No position yet',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schedule(?int $offeringId, Carbon $now): array
    {
        if ($offeringId === null) {
            return [];
        }

        return TimetableSlot::query()
            ->with(['subject', 'staff.user'])
            ->where('class_section_offering_id', $offeringId)
            ->where('day_of_week', $now->isoWeekday())
            ->orderBy('starts_at')
            ->get()
            ->map(function (TimetableSlot $slot) use ($now) {
                $start = $this->at($now, $slot->starts_at);
                $end = $this->at($now, $slot->ends_at);
                $teacher = trim((string) ($slot->staff?->user?->name ?? ''));

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
                    'starts_at' => $start->format('g:i A'),
                    'ends_at' => $end->format('g:i A'),
                    'subject' => $slot->subject?->name ?: ($slot->label ?: 'Period'),
                    'teacher' => $teacher !== '' ? $teacher : null,
                    'status' => $status,
                    'status_label' => match ($status) {
                        'now' => 'Current',
                        'next' => 'Next',
                        'done' => 'Done',
                        default => null,
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Assignment>  $assignments
     * @return list<array<string, mixed>>
     */
    private function assignmentRows(Collection $assignments, Carbon $now): array
    {
        $today = $now->toDateString();
        $open = $assignments->filter(
            fn (Assignment $row) => $row->due_on === null || $row->due_on->toDateString() >= $today,
        );
        $overdue = $assignments->filter(
            fn (Assignment $row) => $row->due_on !== null && $row->due_on->toDateString() < $today,
        );

        return $open->concat($overdue)->take(5)->map(function (Assignment $row) use ($today) {
            $due = $row->due_on?->toDateString();

            return [
                'id' => $row->id,
                'title' => $row->title,
                'subject' => $row->subject?->name,
                'due_on' => $due,
                'due_label' => $row->due_on?->format('j M'),
                'overdue' => $due !== null && $due < $today,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notices(User $user, Carbon $now): array
    {
        return $this->announcements->visibleTo($user)
            ->limit(4)
            ->get()
            ->map(function (Announcement $row) use ($now) {
                $when = $row->published_at ?? $row->created_at;

                return [
                    'id' => $row->id,
                    'title' => $row->title,
                    'excerpt' => Str::limit(trim(strip_tags((string) $row->body)), 90),
                    'when' => $this->relativeWhen($when?->timezone('Africa/Lagos'), $now),
                    'category' => $row->category?->value ?: AnnouncementCategory::General->value,
                ];
            })
            ->values()
            ->all();
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

    private function averageCopy(float $percent): string
    {
        return match (true) {
            $percent >= 85 => 'Excellent performance',
            $percent >= 70 => 'Good performance',
            $percent >= 50 => 'Fair performance',
            default => 'Needs improvement',
        };
    }

    private function attendanceCopy(float $percent): string
    {
        return match (true) {
            $percent >= 90 => 'Excellent attendance',
            $percent >= 75 => 'Good attendance',
            default => 'Watch your attendance',
        };
    }

    private function relativeWhen(?Carbon $when, Carbon $now): string
    {
        if ($when === null) {
            return '—';
        }

        if ($when->isSameDay($now)) {
            $minutes = (int) round($when->diffInMinutes($now, true));
            if ($minutes < 1) {
                return 'Just now';
            }
            if ($minutes < 60) {
                return $minutes === 1 ? '1 minute ago' : $minutes.' minutes ago';
            }
            $hours = max(1, (int) round($minutes / 60));

            return $hours === 1 ? '1 hour ago' : $hours.' hours ago';
        }

        if ($when->isSameDay($now->copy()->subDay())) {
            return 'Yesterday';
        }

        return $when->format('j M');
    }

    private function ordinal(int $number): string
    {
        $mod = $number % 100;
        $suffix = ($mod >= 11 && $mod <= 13)
            ? 'th'
            : match ($number % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            };

        return $number.$suffix;
    }

    private function brandName(?SchoolSetting $settings): string
    {
        $name = trim((string) ($settings?->name ?: 'Supreme Reagan Schools'));
        $short = preg_replace('/\s+Schools$/i', '', $name);

        return trim((string) $short) !== '' ? (string) $short : $name;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));

        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1).substr($parts[array_key_last($parts)], 0, 1));
        }

        return strtoupper(substr($name !== '' ? $name : 'P', 0, 2));
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
