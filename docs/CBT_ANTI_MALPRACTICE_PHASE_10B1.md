# CBT Anti-Malpractice + Automatic Submission (Phase 10B.1)

Strict school policy:

> Once a student starts an examination, leaving the active examination page/window automatically submits the attempt.

This is a **browser-based deterrent / integrity control**, not dedicated proctoring software. Capable students can disable or bypass client JavaScript. The **Laravel server remains authoritative**.

## Triggers (client)

After `examMonitoringActive` (attempt fully initialized):

| Signal | Behavior |
|--------|----------|
| `visibilitychange` → `hidden` | Immediate exit → auto-submit |
| `window.blur` | Immediate exit → auto-submit (deduped with visibility) |
| `fullscreenchange` exit | Immediate exit → auto-submit if fullscreen was entered |
| Fullscreen unsupported/denied | Exam continues; visibility/focus still apply |

Initialization grace (~1.5s) prevents false positives during load/fullscreen request.

## Server authority

- `POST /api/v1/cbt/attempts/{attempt}/auto-submit`
- Also supported: `POST .../submit` with `reason=auto_submitted_exam_exit`
- Owner-only (`submit` policy → 404 otherwise)
- Reuses `CbtSubmissionService` (idempotent; no duplicate results)
- Persists `cbt_attempts.submission_reason`
- Records `cbt_exam_integrity_events` with unique `event_id`

### Submission reasons

- `student_manual`
- `timer_expired`
- `auto_submitted_exam_exit`
- `system`

### Integrity event types

`tab_hidden`, `window_blur`, `fullscreen_exit`, `fullscreen_unavailable`, `auto_submit_triggered`, `auto_submit_completed`, `auto_submit_failed`

## Online vs offline

**Online:** flush pending answers (best effort) → auto-submit with `keepalive` → server marks submitted → UI locked.

**Offline:** local terminal `autoSubmitting` state; answers + pending auto-submit metadata kept in IndexedDB (`auto_submit:{attempt_uuid}`). UI must **not** claim server submission. Full offline reconciliation is **Phase 10C**.

## Multi-tab

BroadcastChannel `attempt_auto_submitted` locks sibling tabs. Server idempotency is the final guard.

## Admin

Attempts list shows `submission_reason` when present.

## Limitations

- Not a substitute for invigilators or dedicated proctoring.
- OS dialogs, notifications, and mobile UI can cause focus/visibility changes.
- Client APIs can be manipulated; treat as policy enforcement with server finalization.

## Manual browser tests

A tab switch · B minimize · C app switch · D fullscreen exit · E duplicate events · F offline exit (local only) · G manual submit then leave · H timer expiry · I multi-tab

## Phase boundary

```
Phase 10B.1: CBT Anti-Malpractice + Automatic Submission = COMPLETE
Phase 10C: Offline Submission / Reconciliation = NOT IMPLEMENTED
```
