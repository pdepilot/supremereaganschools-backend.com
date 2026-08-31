# Supreme Reagan Schools — Implementation Roadmap

**Date:** 25 August 2026  
**Rule:** Do not start large-scale migrations until `BACKEND_IMPLEMENTATION_AUDIT.md` and `DATABASE_ARCHITECTURE.md` are reviewed.

Frontend source of truth: `C:\Users\ADMIN\OneDrive\Desktop\LWA_S`  
Laravel application: `C:\Laravel-Projects\supreme-reagan-schools`

---

## Serving strategy (decide at the start of implementation)

Recommended:

1. Keep `LWA_S` intact (do not delete or redesign).
2. HTML lives in Laravel `resources/frontend/` and is served by routes (`/about`, `/staff/attendance`, `/portal/dashboard`). Only CSS, JS, and images stay under `public/site/`.
3. Old `/site/*.html` URLs redirect to the Laravel routes. Do not convert pages to Blade unless a layout issue forces it.

Alternative (worse): host `LWA_S` separately and add Sanctum + CORS. Only do this if the sites cannot share a domain.

---

## Phase 0 — Audit (this phase) — COMPLETE

- Inspect Laravel and frontend
- Write audit, schema, roadmap, log
- **No school migrations**

---

## Phase 1 — Laravel foundation (safe, after review)

Non-destructive:

- Set `APP_NAME=Supreme Reagan Schools`
- Register `routes/api.php` with `/api` prefix
- Add JSON response helpers / exception formatting
- Add PHP enums from the schema
- Keep SQLite; do not switch to MySQL without approval

Packages: **none** unless review requires Sanctum.

---

## Phase 2 — Authentication and roles

- Extend `users`
- `roles`, `role_user`
- Seed five live roles: `super_admin`, `school_admin`, `teacher`, `parent`, `student`
- Seed unused-but-named roles: `principal`, `vice_principal`, `accountant`, `staff`
- Login / logout / me endpoints
- Role middleware + base policies
- Tests: login, wrong password, role denied

Frontend (minimal, no redesign):

- Create missing `adminLogin.html` in the same visual family as `superAdminLogin.html`
- Point login forms at the API instead of GET navigation / sessionStorage

---

## Phase 3 — School settings + academic structure — COMPLETE

Implemented:

- `school_settings`, `campuses`
- Sessions, terms, levels, classes, arms (`class_sections`), class-section offerings
- Departments, subjects, `subject_offerings` (no teacher assignment yet)
- Seed Owerri campus, four levels, class/arm catalogue, 2024/2025 + 2025/2026, subjects
- Existing admin pages wired: settings, academic sessions, classes

Not in Phase 3: `class_subject_teacher`, staff, students, enrollments.

---

## Phase 4 — People and enrollment — **complete**

- Staff, students, guardians, links
- Enrollments (and promotions table; no wizard)
- Class teacher and subject teacher assignments
- Admission number / staff number generators
- Student login by admission number (existing `web` guard)
- Admin students + teachers lists
- Staff “my students”
- Parent “my children”
- Policies against IDOR
- Tests: create/update/retrieve student, enroll, unauthorized access

Do **not** start Phase 5 until requested.

---

## Phase 5 — Attendance (daily academic operations) — COMPLETE

Scoped to attendance only (assessments/results were deferred out of this phase).

- Daily `attendance_records` keyed by **enrollment + date**, not student + date
- Bulk class register with an atomic transaction
- Class-teacher marking; subject teachers may view assigned classes but cannot mark
- Parent/student read-only with IDOR protection
- Lightweight `attendance_corrections` audit (who / when / from / to / why)
- Staff attendance page and parent/student attendance page wired to `/api/v1`
- Tests: mark, bulk, unique constraint, teacher/parent/student/admin authorization, summaries

**Not in this phase:** assessments, exams, scores, results, report cards, grade scales.

---

## Phase 6 — Fees — COMPLETE

- Fee types and session/term structures (amounts in kobo)
- One invoice per pupil per term, generated from matching structures
- Invoice items with per-line paid/balance
- Admin posts payments (cash / transfer / POS / other) against an admission number
- Allocations across fee lines in one transaction
- Overpayment rejected
- Payments are voided, never deleted
- Parent/student read-only invoices, history, statement
- Pupil ledger: fees / paid / balance / paid-in-full · partial · outstanding (API-authoritative)
- Unique payment references `SRS-FEE-YYYY-000001`
- No Paystack / Flutterwave

---

## Assessments and results (deferred from Phase 5) — COMPLETE

- `assessment_types` (First CA 15, Second CA 15, Examination 70)
- `assessment_scores` hanging off **enrollment + term + subject + type**
- Stored `term_results` (CA total, exam, total, grade, remark) and `term_summaries` (average, competition rank, class size)
- Configurable `grade_scales` matching the staff filter labels
- Class teacher and assigned subject teacher may enter; parent/student read-only with IDOR protection
- Archived sessions reject writes
- Staff grades page and parent/student academics page wired to `/api/v1`
- Tests: score range, unique cell, bulk atomicity, teacher/parent/student/admin authorization, totals, position

**Not in this work:** report-card PDF designer, class-teacher comment field, admin grades UI, admissions.

---

## Phase 7 — Public admissions and contact — COMPLETE

- Replace mailto with API + stored records
- Multipart document uploads (private disk)
- Admin messages chute reads enquiries + applications

---

## Phase 8 — Communication and classroom extras — COMPLETE

- Announcements (audience-scoped; drafts hidden)
- Internal messages (teacher ↔ assigned parent/student; teacher ↔ teacher; admin ↔ anyone)
- Timetables (admin writes; staff/parent/student read assigned/own class)
- Assignments (no submissions)
- Learning materials (private disk; class-scoped download)
- Notifications (database channel)

---

## Phase 9 — Dashboards, reports, audit log

- Replace mock `data-count` values with API stats
- Enrolment / fees / attendance / staff reports — **admin portal live papers done** (`GET /api/v1/portal-reports`, `/catalogue`, `/generate`, `/export`). Staff form papers already exist. `audit_logs` still open.
- `audit_logs` on fees, results, roles

---

## Phase 10 — Hardening and tests

- `php artisan test` until green
- File download authorization
- CSRF on all writes
- Remove remaining mock rows from wired pages

---

## Explicitly not in the first implementation

- Paystack / Flutterwave
- Distinct principal / accountant portals
- Promotion wizard UI
- Alumni / PTA backends
- Spatie Permission, Breeze, Jetstream, Sanctum (unless origin split)

---

## Next action

Admin, staff, and parents can restore a password from their registered email. Pupils sign in with name or admission number and the parent’s registered phone. Do **not** start another school module until requested.

`audit_logs` remains in numbered Phase 9. Do not invent a payment gateway, exam/report-card papers, or a promotion wizard until requested.
