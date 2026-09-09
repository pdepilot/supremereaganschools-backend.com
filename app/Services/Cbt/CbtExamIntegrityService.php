<?php

namespace App\Services\Cbt;

use App\Enums\CbtIntegrityEventType;
use App\Enums\CbtSubmissionReason;
use App\Models\CbtAttempt;
use App\Models\CbtExamIntegrityEvent;
use App\Models\CbtResult;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10B.1 exam-integrity audit + automatic exam-exit submission.
 * Reuses CbtSubmissionService; does not create a second marking path.
 */
class CbtExamIntegrityService
{
    public function __construct(
        private readonly CbtAttemptService $attempts,
        private readonly CbtSubmissionService $submissions,
    ) {}

    /**
     * @param  array{
     *   event_id?: string,
     *   correlation_id?: ?string,
     *   event_type: string|CbtIntegrityEventType,
     *   client_occurred_at?: mixed,
     *   metadata?: ?array<string, mixed>
     * }  $payload
     */
    public function recordEvent(CbtAttempt $attempt, User $user, array $payload): CbtExamIntegrityEvent
    {
        $this->attempts->assertOwnedBy($attempt, $user);

        $eventId = trim((string) ($payload['event_id'] ?? ''));
        if ($eventId === '') {
            $eventId = (string) Str::uuid();
        }

        $existing = CbtExamIntegrityEvent::query()->where('event_id', $eventId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $type = $payload['event_type'] ?? null;
        if (is_string($type)) {
            $type = CbtIntegrityEventType::tryFrom($type);
        }
        if (! $type instanceof CbtIntegrityEventType) {
            throw ValidationException::withMessages([
                'event_type' => 'A valid integrity event type is required.',
            ]);
        }

        $metadata = $payload['metadata'] ?? null;
        if (is_array($metadata)) {
            unset(
                $metadata['password'],
                $metadata['token'],
                $metadata['is_correct'],
                $metadata['answer_key'],
                $metadata['score'],
                $metadata['grade'],
            );
        } else {
            $metadata = null;
        }

        try {
            return CbtExamIntegrityEvent::query()->create([
                'event_id' => $eventId,
                'correlation_id' => isset($payload['correlation_id']) ? (string) $payload['correlation_id'] : null,
                'attempt_id' => $attempt->id,
                'student_profile_id' => $attempt->student_profile_id,
                'event_type' => $type,
                'occurred_at' => now(),
                'client_occurred_at' => $payload['client_occurred_at'] ?? null,
                'metadata' => $metadata,
            ]);
        } catch (QueryException) {
            $race = CbtExamIntegrityEvent::query()->where('event_id', $eventId)->first();
            if ($race !== null) {
                return $race;
            }

            throw ValidationException::withMessages([
                'event_id' => 'Unable to record integrity event.',
            ]);
        }
    }

    /**
     * Authoritative exam-exit auto-submit. Idempotent via submission service + event_id.
     *
     * @param  array{
     *   integrity_event_id?: string,
     *   correlation_id?: ?string,
     *   trigger?: string,
     *   client_submitted_at?: mixed,
     *   client_occurred_at?: mixed,
     *   metadata?: ?array<string, mixed>
     * }  $payload
     * @return array{result: CbtResult, already_submitted: bool, integrity_event: CbtExamIntegrityEvent}
     */
    public function autoSubmitForExamExit(CbtAttempt $attempt, User $user, array $payload = []): array
    {
        $this->attempts->assertOwnedBy($attempt, $user);

        $integrityEventId = trim((string) ($payload['integrity_event_id'] ?? ''));
        if ($integrityEventId === '') {
            $integrityEventId = (string) Str::uuid();
        }

        $trigger = (string) ($payload['trigger'] ?? 'tab_hidden');
        $eventType = match ($trigger) {
            'window_blur' => CbtIntegrityEventType::WindowBlur,
            'fullscreen_exit' => CbtIntegrityEventType::FullscreenExit,
            default => CbtIntegrityEventType::TabHidden,
        };

        $triggerEvent = $this->recordEvent($attempt, $user, [
            'event_id' => $integrityEventId,
            'correlation_id' => $payload['correlation_id'] ?? $integrityEventId,
            'event_type' => $eventType,
            'client_occurred_at' => $payload['client_occurred_at'] ?? $payload['client_submitted_at'] ?? null,
            'metadata' => array_merge($payload['metadata'] ?? [], [
                'auto_submit' => true,
                'trigger' => $trigger,
            ]),
        ]);

        $hadResult = $attempt->result()->exists();
        $correlationId = (string) ($payload['correlation_id'] ?? $integrityEventId);

        if ($hadResult) {
            return [
                'result' => $attempt->result()->firstOrFail(),
                'already_submitted' => true,
                'integrity_event' => $triggerEvent,
            ];
        }

        $this->recordEvent($attempt, $user, [
            'event_id' => (string) Str::uuid(),
            'correlation_id' => $correlationId,
            'event_type' => CbtIntegrityEventType::AutoSubmitTriggered,
            'client_occurred_at' => $payload['client_occurred_at'] ?? null,
            'metadata' => ['trigger' => $trigger, 'primary_event_id' => $integrityEventId],
        ]);

        try {
            $result = $this->submissions->submit($attempt, $user, [
                'client_submitted_at' => $payload['client_submitted_at'] ?? null,
                'reason' => CbtSubmissionReason::AutoSubmittedExamExit,
            ]);

            $this->recordEvent($attempt, $user, [
                'event_id' => (string) Str::uuid(),
                'correlation_id' => $correlationId,
                'event_type' => CbtIntegrityEventType::AutoSubmitCompleted,
                'client_occurred_at' => $payload['client_occurred_at'] ?? null,
                'metadata' => [
                    'already_submitted' => false,
                    'primary_event_id' => $integrityEventId,
                    'submission_reason' => CbtSubmissionReason::AutoSubmittedExamExit->value,
                ],
            ]);

            return [
                'result' => $result,
                'already_submitted' => false,
                'integrity_event' => $triggerEvent,
            ];
        } catch (ValidationException $exception) {
            $this->recordEvent($attempt, $user, [
                'event_id' => (string) Str::uuid(),
                'correlation_id' => $correlationId,
                'event_type' => CbtIntegrityEventType::AutoSubmitFailed,
                'client_occurred_at' => $payload['client_occurred_at'] ?? null,
                'metadata' => [
                    'primary_event_id' => $integrityEventId,
                    'message' => collect($exception->errors())->flatten()->first(),
                ],
            ]);

            throw $exception;
        }
    }
}
