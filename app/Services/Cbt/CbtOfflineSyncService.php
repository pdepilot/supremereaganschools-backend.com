<?php

namespace App\Services\Cbt;

use App\Enums\CbtAttemptStatus;
use App\Enums\CbtSyncDirection;
use App\Enums\CbtSyncLogStatus;
use App\Enums\CbtSyncStatus;
use App\Models\CbtAttempt;
use App\Models\CbtSyncLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10B: batched offline answer synchronization (cbt-offline-sync-v1).
 * Server remains authoritative for deadlines, ownership, and frozen snapshots.
 */
class CbtOfflineSyncService
{
    public const PROTOCOL = 'cbt-offline-sync-v1';

    public const MAX_EVENTS_PER_BATCH = 50;

    public function __construct(
        private readonly CbtAnswerService $answers,
        private readonly CbtAttemptService $attempts,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array{protocol: string, attempt_uuid: string, attempt_id: int, batch_id: string, results: list<array<string, mixed>>}
     */
    public function syncAnswers(CbtAttempt $attempt, User $user, array $events, ?string $batchId = null): array
    {
        $this->attempts->assertOwnedBy($attempt, $user);

        if ($events === []) {
            throw ValidationException::withMessages([
                'events' => 'At least one sync event is required.',
            ]);
        }

        if (count($events) > self::MAX_EVENTS_PER_BATCH) {
            throw ValidationException::withMessages([
                'events' => 'A sync batch may contain at most '.self::MAX_EVENTS_PER_BATCH.' events.',
            ]);
        }

        $batchId = is_string($batchId) && $batchId !== '' ? $batchId : (string) str()->uuid();

        // Deterministic client ordering: local_sequence then client_answered_at then event_id.
        usort($events, function (array $a, array $b): int {
            $seqA = (int) ($a['local_sequence'] ?? 0);
            $seqB = (int) ($b['local_sequence'] ?? 0);
            if ($seqA !== $seqB) {
                return $seqA <=> $seqB;
            }

            $timeA = (string) ($a['client_answered_at'] ?? '');
            $timeB = (string) ($b['client_answered_at'] ?? '');
            if ($timeA !== $timeB) {
                return $timeA <=> $timeB;
            }

            return strcmp((string) ($a['event_id'] ?? ''), (string) ($b['event_id'] ?? ''));
        });

        $results = [];
        foreach ($events as $event) {
            $results[] = $this->processEvent($attempt, $user, $event, $batchId);
        }

        return [
            'protocol' => self::PROTOCOL,
            'attempt_uuid' => (string) $attempt->uuid,
            'attempt_id' => (int) $attempt->id,
            'batch_id' => $batchId,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function processEvent(CbtAttempt $attempt, User $user, array $event, string $batchId): array
    {
        $eventId = trim((string) ($event['event_id'] ?? ''));
        if ($eventId === '' || strlen($eventId) > 64) {
            return $this->reject(null, 'rejected_invalid', 'event_id is required and must be at most 64 characters.');
        }

        $existing = CbtSyncLog::query()->where('event_id', $eventId)->first();
        if ($existing !== null) {
            return $this->alreadySyncedResult($eventId, $existing);
        }

        $type = (string) ($event['type'] ?? 'answer');
        if ($type !== 'answer') {
            return $this->recordRejection($attempt, $user, $eventId, $batchId, 'rejected_invalid', 'Unsupported event type.');
        }

        if ((string) ($event['attempt_uuid'] ?? $attempt->uuid) !== (string) $attempt->uuid) {
            return $this->recordRejection($attempt, $user, $eventId, $batchId, 'rejected_invalid', 'attempt_uuid does not match this attempt.');
        }

        $fresh = $attempt->fresh();
        if ($fresh === null) {
            return $this->recordRejection($attempt, $user, $eventId, $batchId, 'rejected_invalid', 'Attempt not found.');
        }

        if ($fresh->status !== CbtAttemptStatus::InProgress) {
            return $this->recordRejection(
                $fresh,
                $user,
                $eventId,
                $batchId,
                'rejected_closed',
                'Attempt is closed and cannot accept synchronized answers.'
            );
        }

        if ($this->attempts->isExpired($fresh)) {
            return $this->recordRejection(
                $fresh,
                $user,
                $eventId,
                $batchId,
                'rejected_expired',
                'Authoritative attempt deadline has passed.'
            );
        }

        try {
            $save = $this->answers->saveFromSync($fresh, $user, [
                'exam_question_id' => (int) ($event['exam_question_id'] ?? 0),
                'selected_exam_option_id' => array_key_exists('selected_exam_option_id', $event)
                    ? $event['selected_exam_option_id']
                    : (array_key_exists('selected_option_id', $event) ? $event['selected_option_id'] : null),
                'client_answered_at' => $event['client_answered_at'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            $status = $this->mapValidationToStatus($exception);

            return $this->recordRejection(
                $fresh,
                $user,
                $eventId,
                $batchId,
                $status,
                collect($exception->errors())->flatten()->first() ?: 'Validation failed.'
            );
        }

        $answer = $save['answer'];
        $applied = (bool) $save['applied'];
        $resultStatus = $applied ? 'synced' : 'already_synced';

        $payload = [
            'event_id' => $eventId,
            'status' => $resultStatus,
            'exam_question_id' => $answer->exam_question_id,
            'selected_exam_option_id' => $answer->selected_exam_option_id,
            'answered_at' => optional($answer->answered_at)?->toIso8601String(),
            'sync_status' => CbtSyncStatus::Synced->value,
        ];

        try {
            CbtSyncLog::query()->create([
                'event_id' => $eventId,
                'attempt_id' => $fresh->id,
                'user_id' => $user->id,
                'direction' => CbtSyncDirection::ClientToServer,
                'protocol' => self::PROTOCOL,
                'batch_id' => $batchId,
                'payload_hash' => hash('sha256', $eventId),
                'status' => $applied ? CbtSyncLogStatus::Accepted : CbtSyncLogStatus::Duplicate,
                'message' => json_encode([
                    'result' => $resultStatus,
                    'exam_question_id' => $answer->exam_question_id,
                    'selected_exam_option_id' => $answer->selected_exam_option_id,
                    'answered_at' => optional($answer->answered_at)?->toIso8601String(),
                    'reason' => $save['reason'] ?? null,
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (QueryException $exception) {
            // Concurrent duplicate insert of the same event_id.
            $race = CbtSyncLog::query()->where('event_id', $eventId)->first();
            if ($race !== null) {
                return $this->alreadySyncedResult($eventId, $race);
            }

            throw $exception;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function alreadySyncedResult(string $eventId, CbtSyncLog $log): array
    {
        $decoded = [];
        if (is_string($log->message) && $log->message !== '') {
            try {
                $decoded = json_decode($log->message, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = [];
            }
        }

        if (! is_array($decoded)) {
            $decoded = [];
        }

        $result = (string) ($decoded['result'] ?? 'already_synced');
        if (str_starts_with($result, 'rejected_')) {
            return [
                'event_id' => $eventId,
                'status' => $result,
                'reason' => $decoded['reason'] ?? 'Previously rejected.',
            ];
        }

        return [
            'event_id' => $eventId,
            'status' => 'already_synced',
            'exam_question_id' => $decoded['exam_question_id'] ?? null,
            'selected_exam_option_id' => $decoded['selected_exam_option_id'] ?? null,
            'answered_at' => $decoded['answered_at'] ?? null,
            'sync_status' => CbtSyncStatus::Synced->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordRejection(
        CbtAttempt $attempt,
        User $user,
        string $eventId,
        string $batchId,
        string $status,
        string $reason,
    ): array {
        try {
            CbtSyncLog::query()->create([
                'event_id' => $eventId,
                'attempt_id' => $attempt->id,
                'user_id' => $user->id,
                'direction' => CbtSyncDirection::ClientToServer,
                'protocol' => self::PROTOCOL,
                'batch_id' => $batchId,
                'payload_hash' => hash('sha256', $eventId),
                'status' => CbtSyncLogStatus::Rejected,
                'message' => json_encode([
                    'result' => $status,
                    'reason' => $reason,
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (QueryException) {
            $race = CbtSyncLog::query()->where('event_id', $eventId)->first();
            if ($race !== null) {
                return $this->alreadySyncedResult($eventId, $race);
            }
        }

        return $this->reject($eventId, $status, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function reject(?string $eventId, string $status, string $reason): array
    {
        return [
            'event_id' => $eventId,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    private function mapValidationToStatus(ValidationException $exception): string
    {
        $flat = strtolower(collect($exception->errors())->flatten()->implode(' '));

        if (str_contains($flat, 'expired')) {
            return 'rejected_expired';
        }

        if (str_contains($flat, 'in-progress') || str_contains($flat, 'submitted') || str_contains($flat, 'closed')) {
            return 'rejected_closed';
        }

        if (str_contains($flat, 'option')) {
            return 'rejected_invalid_option';
        }

        if (str_contains($flat, 'question')) {
            return 'rejected_invalid_question';
        }

        return 'rejected_invalid';
    }
}
