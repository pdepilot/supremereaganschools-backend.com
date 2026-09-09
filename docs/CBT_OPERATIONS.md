# CBT Operations (Phase 9)

Practical guide for Examination Officers using CBT monitoring and reporting.

## Permissions

- `cbt.manage` — full administration and reporting
- `cbt.mark` — results, attempts, monitor, reports (no question-bank edits)
- Students cannot access `/cbt/admin/*` or `/api/v1/cbt/admin/*`

## Dashboard (`/cbt/admin`)

Live aggregate cards:

- Total / draft / published / active exams
- Completed and in-progress attempts
- Results generated, locked, unlocked
- Result Checker paid / pending / failed counts and revenue (kobo)

Revenue includes only `online_payments` with purpose `cbt_result_checker` and status `paid`.

## Active monitor (`/cbt/admin/monitor`)

Polls `GET /api/v1/cbt/admin/attempts/monitor` every 30 seconds.

Per active published exam: assigned, started, in progress, submitted, expired, not started, completion %.

Expired means `in_progress` with `ends_at` in the past (operational view; historical timestamps are not rewritten).

## Attempts (`/cbt/admin/attempts`)

Paginated attempt list with filters: exam, student, status (`in_progress`, `submitted`, `expired`, `abandoned`), dates.

### Timer controls

- `POST /api/v1/cbt/admin/attempts/{attempt}/extend` — `{ "minutes": N }` or `{ "reset": true }` (optional `minutes` with reset to override duration). Requires `cbt.manage` or `cbt.mark`. Only `in_progress` attempts (including operationally expired ones).
- `POST /api/v1/cbt/admin/exams/{exam}/extend-timers` — same payload; applies to all in-progress attempts for that exam.
- `POST /api/v1/cbt/admin/exams/{exam}/schedule` — update `duration_minutes` / `starts_at` / `ends_at` / `is_active` on published exams for **future** starts only (`cbt.manage`). Does not rewrite live attempt `ends_at`.

Reset sets `ends_at = now + duration` (or supplied minutes). Extend adds minutes from the later of `now` and current `ends_at`, so expired writers can be reopened.

## Reports (`/cbt/admin/reports`)

### Exam performance

`GET /api/v1/cbt/admin/exams/{exam}/report`

Includes participation, averages, pass rate, student roster, and question analytics from **frozen** `cbt_exam_questions` snapshots.

Analytics difficulty bands (report-only): easy ≥70%, moderate 40–69%, difficult <40%.

### Student history

`GET /api/v1/cbt/admin/reports/student-history?student_id=`

Shows scores and Result Checker lock/unlock + payment status.

## CSV exports

Authorized only (`cbt.manage` or `cbt.mark`):

- `/api/v1/cbt/admin/exams/{exam}/export/results`
- `/api/v1/cbt/admin/exams/{exam}/export/attendance`
- `/api/v1/cbt/admin/reports/student-history/export?student_id=`

## Production deploy

Additive only:

```bash
php artisan migrate:status
php artisan migrate --force
php artisan optimize:clear
```

Do **not** run `migrate:fresh` / `db:wipe`.

No new `.env` keys are required for Phase 9 beyond existing CBT/Paystack settings.
