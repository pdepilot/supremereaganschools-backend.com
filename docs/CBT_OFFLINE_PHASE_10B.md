# CBT Offline Synchronization (Phase 10B)

Phase 10B synchronizes **answers only**. Offline submission/reconciliation is **Phase 10C**.

## Protocol

`cbt-offline-sync-v1`

### Endpoint

`POST /api/v1/cbt/attempts/{attempt}/sync`  
Middleware: authenticated CBT desk student; `answer` policy (owner-only → 404 otherwise). Throttle: 60/min.

### Request

```json
{
  "protocol": "cbt-offline-sync-v1",
  "batch_id": "optional-uuid",
  "events": [
    {
      "event_id": "uuid",
      "type": "answer",
      "attempt_uuid": "…",
      "exam_question_id": 12,
      "selected_exam_option_id": 45,
      "client_answered_at": "2026-09-09T14:01:02.000Z",
      "local_sequence": 3
    }
  ]
}
```

Batch size max: **50** (server), **25** (browser default).

### Response (ApiResponse envelope)

```json
{
  "success": true,
  "message": "Offline answer synchronization processed.",
  "data": {
    "protocol": "cbt-offline-sync-v1",
    "attempt_uuid": "…",
    "attempt_id": 1,
    "batch_id": "…",
    "results": [
      { "event_id": "…", "status": "synced", "exam_question_id": 12, "selected_exam_option_id": 45, "answered_at": "…" },
      { "event_id": "…", "status": "already_synced" },
      { "event_id": "…", "status": "rejected_expired", "reason": "…" }
    ]
  }
}
```

Per-event statuses: `synced`, `already_synced`, `rejected_expired`, `rejected_closed`, `rejected_invalid_question`, `rejected_invalid_option`, `rejected_invalid`.

## Idempotency

- Each event requires a client `event_id` (≤64 chars).
- Stored uniquely on `cbt_sync_logs.event_id`.
- Retries after lost responses return `already_synced` (or the prior permanent rejection) without duplicating `cbt_answers`.
- Answer uniqueness remains `(attempt_id, exam_question_id)`.

## Ordering / LWW

- Batch sorted by `local_sequence`, then `client_answered_at`, then `event_id`.
- Client timestamps are **ordering hints only** — they never extend `ends_at`.
- Stale events (older `client_answered_at` than the stored answer) become `already_synced` / no-op.

## Expiry & closed attempts

- Server deadline (`ends_at`) is authoritative → late sync → `rejected_expired`.
- Submitted/closed attempts → `rejected_closed` (attempt is not reopened).
- Phase 10B does **not** reconcile offline-after-deadline submissions (Phase 10C).

## Queue lifecycle (IndexedDB `sync_queue`)

`pending` → `syncing` → `synced` | `rejected` | `failed` (retryable)

- Acknowledgment required before marking synced.
- Permanent rejections are not retried endlessly.
- Retryable failures use exponential backoff (2s → 60s cap).
- Multi-tab: BroadcastChannel lock hint; server idempotency is final protection.
- Online autosave success also clears pending queue rows for that question (compatible pipelines).

## Security

- Ownership enforced; cross-student sync → 404.
- Frozen exam question/option validation.
- No answer keys, scores, grades, or pass/fail in sync responses.
- Sync does not mark or submit exams.

## Migration

Additive only: `event_id` (unique), `protocol`, `batch_id` on `cbt_sync_logs`.

## Manual test plan

A. Offline answers → queue pending  
B. Reconnect → ack → pending decreases; UI says synced only after ack  
C. Refresh while pending → recover → sync  
D. Browser restart → sync  
E. Duplicate retry → one answer row  
F. Past deadline → `rejected_expired`, no extension  
G. Two tabs → deterministic final answer, no duplicate rows  

## Phase boundary

```
Phase 10B: SERVER SYNCHRONIZATION = COMPLETE
Phase 10C: OFFLINE SUBMISSION / RECONCILIATION = NOT IMPLEMENTED
```
