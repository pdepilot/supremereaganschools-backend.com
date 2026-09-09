<?php

namespace App\Services\Cbt;

use App\Enums\CbtAttemptMode;
use App\Enums\CbtAttemptStatus;
use App\Enums\CbtExamStatus;
use App\Enums\CbtSyncStatus;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CbtAttemptService
{
    public function __construct(
        private readonly CbtExamAssignmentService $assignments,
    ) {}

    /**
     * @param  array{uuid?: ?string, mode?: CbtAttemptMode|string|null, device_id?: ?string, ip_address?: ?string}  $options
     */
    public function start(CbtExam $exam, User $user, StudentProfile $student, array $options = []): CbtAttempt
    {
        return DB::transaction(function () use ($exam, $user, $student, $options) {
            /** @var CbtExam $exam */
            $exam = CbtExam::query()->lockForUpdate()->findOrFail($exam->id);

            $uuid = $options['uuid'] ?? null;
            if (is_string($uuid) && $uuid !== '') {
                $existingByUuid = CbtAttempt::query()->where('uuid', $uuid)->lockForUpdate()->first();
                if ($existingByUuid !== null) {
                    if ((int) $existingByUuid->exam_id !== (int) $exam->id
                        || (int) $existingByUuid->student_profile_id !== (int) $student->id
                        || (int) $existingByUuid->user_id !== (int) $user->id
                    ) {
                        throw ValidationException::withMessages([
                            'uuid' => 'Attempt uuid belongs to a different exam or student.',
                        ]);
                    }

                    return $existingByUuid->fresh(['exam', 'answers']) ?? $existingByUuid;
                }
            }

            $this->assertExamStartable($exam);
            $this->assignments->assertStudentEligible($exam, $student);

            if ((int) ($student->user_id ?? 0) !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'user' => 'Authenticated user does not match the student profile.',
                ]);
            }

            $active = CbtAttempt::query()
                ->where('exam_id', $exam->id)
                ->where('student_profile_id', $student->id)
                ->where('status', CbtAttemptStatus::InProgress)
                ->lockForUpdate()
                ->get();

            foreach ($active as $attempt) {
                if ($this->isExpired($attempt)) {
                    // Lazy resolve avoids a constructor cycle with CbtSubmissionService.
                    app(CbtSubmissionService::class)->submit($attempt, $user, [
                        'reason' => 'timer_expired',
                    ]);
                } else {
                    throw ValidationException::withMessages([
                        'attempt' => 'An active attempt already exists for this exam.',
                    ]);
                }
            }

            $used = CbtAttempt::query()
                ->where('exam_id', $exam->id)
                ->where('student_profile_id', $student->id)
                ->where('status', '!=', CbtAttemptStatus::Void)
                ->count();

            if ($used >= (int) $exam->max_attempts) {
                throw ValidationException::withMessages([
                    'attempt' => 'Maximum attempts for this exam have been reached.',
                ]);
            }

            $startedAt = now();
            if ($exam->starts_at !== null && $startedAt->lt($exam->starts_at)) {
                throw ValidationException::withMessages([
                    'exam' => 'This exam is not open yet.',
                ]);
            }

            if ($exam->ends_at !== null && $startedAt->gte($exam->ends_at)) {
                throw ValidationException::withMessages([
                    'exam' => 'This exam is closed.',
                ]);
            }

            $endsAt = $startedAt->copy()->addMinutes((int) $exam->duration_minutes);
            if ($exam->ends_at !== null && $endsAt->gt($exam->ends_at)) {
                $endsAt = $exam->ends_at->copy();
            }

            $mode = $options['mode'] ?? CbtAttemptMode::Online;
            if (is_string($mode)) {
                $mode = CbtAttemptMode::from($mode);
            }

            $enrollment = $this->assignments->activeEnrollmentForExam($exam, $student);

            return CbtAttempt::query()->create([
                'uuid' => is_string($uuid) && $uuid !== '' ? $uuid : (string) Str::uuid(),
                'exam_id' => $exam->id,
                'user_id' => $user->id,
                'student_profile_id' => $student->id,
                'enrollment_id' => $enrollment?->id,
                'device_id' => $options['device_id'] ?? null,
                'status' => CbtAttemptStatus::InProgress,
                'mode' => $mode,
                'sync_status' => CbtSyncStatus::Pending,
                'started_at' => $startedAt,
                'ends_at' => $endsAt,
                'ip_address' => $options['ip_address'] ?? null,
            ]);
        });
    }

    public function assertOwnedBy(CbtAttempt $attempt, User $user, ?StudentProfile $student = null): void
    {
        if ((int) $attempt->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'attempt' => 'Attempt does not belong to the authenticated user.',
            ]);
        }

        if ($student !== null && (int) $attempt->student_profile_id !== (int) $student->id) {
            throw ValidationException::withMessages([
                'attempt' => 'Attempt does not belong to this student.',
            ]);
        }
    }

    public function isExpired(CbtAttempt $attempt, $now = null): bool
    {
        $now ??= now();

        if ($attempt->status !== CbtAttemptStatus::InProgress) {
            return false;
        }

        return $attempt->ends_at !== null && $now->gte($attempt->ends_at);
    }

    public function assertAcceptsAnswers(CbtAttempt $attempt): void
    {
        if ($attempt->status !== CbtAttemptStatus::InProgress) {
            throw ValidationException::withMessages([
                'attempt' => 'Answers can only be saved on an in-progress attempt.',
            ]);
        }

        if ($this->isExpired($attempt)) {
            throw ValidationException::withMessages([
                'attempt' => 'This attempt has expired.',
            ]);
        }
    }

    /**
     * Admin timer control for an in-progress attempt.
     * Extending an operationally expired attempt re-opens the writing window.
     *
     * @param  array{minutes?: int, reset?: bool}  $options
     */
    public function extendTimer(CbtAttempt $attempt, array $options = []): CbtAttempt
    {
        return DB::transaction(function () use ($attempt, $options) {
            /** @var CbtAttempt $locked */
            $locked = CbtAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $locked->loadMissing('exam');

            if ($locked->status !== CbtAttemptStatus::InProgress) {
                throw ValidationException::withMessages([
                    'attempt' => 'Only in-progress attempts can have their timer adjusted.',
                ]);
            }

            $reset = (bool) ($options['reset'] ?? false);
            $minutes = isset($options['minutes']) ? (int) $options['minutes'] : null;

            if ($reset) {
                $duration = $minutes !== null && $minutes > 0
                    ? $minutes
                    : max(1, (int) ($locked->exam?->duration_minutes ?? 1));
                $newEnds = now()->addMinutes($duration);
            } else {
                if ($minutes === null || $minutes < 1) {
                    throw ValidationException::withMessages([
                        'minutes' => 'Provide at least 1 minute to extend, or use reset.',
                    ]);
                }

                $base = $locked->ends_at && $locked->ends_at->gt(now())
                    ? $locked->ends_at->copy()
                    : now();
                $newEnds = $base->addMinutes($minutes);
            }

            $examEnds = $locked->exam?->ends_at;
            if ($examEnds !== null && $newEnds->gt($examEnds)) {
                // Allow admin extension past exam window — operational override.
                // Exam schedule still gates new starts; live attempts may be extended.
            }

            $locked->update(['ends_at' => $newEnds]);

            return $locked->fresh(['exam', 'studentProfile', 'result']) ?? $locked;
        });
    }

    /**
     * @param  array{minutes?: int, reset?: bool}  $options
     * @return array{updated: int, attempts: list<CbtAttempt>}
     */
    public function extendTimersForExam(CbtExam $exam, array $options = []): array
    {
        $attempts = CbtAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('status', CbtAttemptStatus::InProgress)
            ->orderBy('id')
            ->get();

        $updated = [];
        foreach ($attempts as $attempt) {
            $updated[] = $this->extendTimer($attempt, $options);
        }

        return [
            'updated' => count($updated),
            'attempts' => $updated,
        ];
    }

    private function assertExamStartable(CbtExam $exam): void
    {
        if ($exam->status !== CbtExamStatus::Published) {
            throw ValidationException::withMessages([
                'exam' => 'Only published exams can be started.',
            ]);
        }

        if (! $exam->is_active || $exam->status === CbtExamStatus::Archived) {
            throw ValidationException::withMessages([
                'exam' => 'This exam is not active.',
            ]);
        }
    }
}
