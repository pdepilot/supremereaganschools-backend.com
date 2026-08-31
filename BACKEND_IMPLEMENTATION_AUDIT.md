# Supreme Reagan Schools — Backend Implementation Audit

**Date:** 25 August 2026  
**Status:** Audit complete. No school-management migrations, models, or APIs have been implemented yet.  
**Instruction followed:** Inspect first. Do not begin large-scale database implementation until this architecture is reviewed.

---

## How to read this document

This audit records **what actually exists** in the workspace. Proposed modules are derived from the existing frontend, not from a generic school-management checklist.

Two folders were inspected:

| Role | Path | What it is |
|------|------|------------|
| Laravel application (backend foundation) | `C:\Laravel-Projects\supreme-reagan-schools` | Fresh Laravel 13 skeleton |
| Existing Supreme Reagan Schools frontend (source of truth for UI) | `C:\Users\ADMIN\OneDrive\Desktop\LWA_S` | Static HTML/CSS/JS school website + portals |

The frontend folder is named `LWA_S` (Living Word Academy heritage is still visible in leftover emails such as `mary.okafor@lwa.edu.ng`). The public brand in the UI is **Supreme Reagan Schools**. The Laravel folder name is `supreme-reagan-schools`. There is **no frontend inside the Laravel project** today.

---

## A. Current architecture

### Runtime

| Item | Value | Source |
|------|--------|--------|
| Laravel | 13.26.1 (`laravel/framework: ^13.17`) | `composer.json`, `php artisan --version` |
| PHP | 8.3.32 (CLI NTS, Windows) | `php -v`, `php artisan about` |
| Composer | 2.10.1 | `php artisan about` |
| Node frontend toolchain in Laravel | Vite 8 + Tailwind 4 | `package.json` (unused by the school UI) |
| Application name | still `Laravel` | `.env` `APP_NAME` |
| Environment | `local`, debug **enabled** | `.env` |
| URL | `http://localhost:8000` | `.env` |

### Database

| Item | Value |
|------|--------|
| Connection | `sqlite` |
| File | `database/database.sqlite` (exists, ~98 KB) |
| MySQL | configured in `config/database.php` but **not** used |
| Migrations run | default Laravel 13 batch only |
| School tables | **none** |

Ran migrations:

1. `0001_01_01_000000_create_users_table` — `users`, `password_reset_tokens`, `sessions`
2. `0001_01_01_000001_create_cache_table` — `cache`, `cache_locks`
3. `0001_01_01_000002_create_jobs_table` — `jobs`, `job_batches`, `failed_jobs`

Default seeder creates a factory user `test@example.com`. That is Laravel skeleton data, not school data.

### Mail, files, queue, session

| Concern | Current setting |
|---------|-----------------|
| Mail | `log` driver (writes to log; no SMTP) |
| Filesystem | `local` → `storage/app/private` |
| Public disk | configured, **storage link not verified as created** |
| Queue | `database` |
| Cache | `database` |
| Session | `database`, 120 minutes, not encrypted |
| Broadcasting | `log` |

### Request flow today

```
Browser
  → LWA_S static HTML (no backend)
  → forms either GET-navigate to another HTML page, open mailto:, or preventDefault and store sessionStorage

Laravel
  → GET /  → welcome.blade.php
  → GET /up → health check
  → no API routes registered
```

The two applications are **not connected**.

### Frontend architecture (LWA_S)

- Static multi-page HTML.
- Public site: Bootstrap 5.3 + custom `CSS/index.css`, `CSS/branches.css`.
- Admin “command desk”: custom `CSS/admin-command.css` + `JS/admin-auth.js` + `JS/admin-command.js`.
- Staff portal: Bootstrap 5.3 + `CSS/staff.css`, inline JS per page.
- Parent/student portal: Bootstrap 5.3 + `CSS/parent_student.css`, inline JS per page.
- **No SPA framework.** No `fetch()`, axios, or Laravel API calls anywhere in the frontend.
- **No authentication against a server.** Admin login writes `sessionStorage.srsCommand` and redirects. Staff/parent/student logins use `<form method="get">` and ignore credentials.

---

## B. Existing frontend modules

### B1. Public marketing site

| Page | Purpose |
|------|---------|
| `index.html` | Home, hero, values, facilities, clubs, footer, portal links |
| `about.html` | About the school |
| `admissions.html` | Admission copy + full application form |
| `nursery.html` | Nursery programme |
| `primary.html` | Primary programme |
| `secondary.html` | Secondary programme |
| `branches.html` | Nursery / Primary / Secondary as school “branches” (marketing, not multi-campus ops) |
| `contact.html` | Enquiry form (currently mailto) |
| `pta.html` | PTA stub (navbar only; unfinished) |

Public identity hard-coded in the UI:

- School: Supreme Reagan Schools
- Address: 15 Spibat Road, Amakohia-Akwakuma, Owerri, Imo State
- Phone: 09065641343
- Email: `supremereagansch@gmail.com`
- Admissions contact: Mrs. Ezinne Ibeaja / `ibeajaezinne@gmail.com`
- Founded: 13 September 2010
- WhatsApp: `https://wa.me/2349065641343`

### B2. Authentication interfaces

| Page | Intended role | Fields collected | What it actually does |
|------|---------------|------------------|------------------------|
| `superAdminLogin.html` | Super Admin / “Sovereign” | email, password, clearance key, remember | Fake unlock animation; stores `{role:"sovereign"}` in `sessionStorage`; redirects to `admin/dashboard.html` |
| `adminLogin.html` | School Admin | **FILE DOES NOT EXIST** | Linked from the public site, every admin page logout, and `superAdminLogin.html` |
| `staffLogin.html` | Teacher / staff | email, password, remember | GET submit to `staff/staff.html`; no credential check |
| `Parent_studentlogin.html` | Parent | admission number, password, remember | GET submit to `parent_student/dashboard.html`; no credential check |
| `parent_studentPage.html` | Student | admission number, password, remember | GET submit to `parent_student/student_dashboard.html`; no credential check |

Forgot-password links exist and go to `#`.

### B3. Admin command desk (`admin/`)

A complete visual admin UI with mock metrics and tables. Buttons labelled “Register a pupil”, “Appoint a master”, “Post to ledger”, “Dispatch notice”, “Seal the year” are `type="button"` and **do not submit data**.

| Page | Expected functionality | Data shown (mock) |
|------|------------------------|-------------------|
| `dashboard.html` | School overview | 1,248 pupils, 86 staff, ₦48.6M fees, 42 forms, tickets, inbox |
| `students.html` | Pupil directory, search/filter, register | Name, sex, admission no. `SRS/YYYY/NNNN`, form, guardian, fee state, status |
| `teachers.html` | Staff directory, appoint | Name, staff ID `SRS/TCH/NNNN`, department, subject, form, status |
| `classes.html` | Forms / class arms | Form (JSS 2A), level, class teacher, roll cap, campus Owerri, status |
| `academic_sessions.html` | Open/archive sessions | Session name, start/end dates, term count (2 or 3) |
| `timetable.html` | Weekly grid by form | Periods 8:00–11:00+, subject + teacher |
| `fees.html` | Record payment, recent receipts | Admission no., amount, channel (Transfer/Cash/POS), note |
| `announcements.html` | Compose circular | Title, audience (whole school / parents / staff / secondary), body |
| `messages.html` | Inbound enquiry triage | Urgent / Watch / Cleared lanes |
| `reports.html` | Jump-off to roll, fees, staff, session reports | Attendance %, fee collection %, admissions, staff present |
| `settings.html` | School identity + desk access list | Name, address, phone, email; sovereign / admin / staff keys |

Admin JS (`admin-command.js`) only: sessionStorage gate, Lagos clock, greeting “Mrs. Ibeaja”, logout, count-up animation, toast.

### B4. Staff portal (`staff/`)

Class-teacher workspace. Mock user: **Mrs. Mary Okafor**, Class Teacher, email leftover `mary.okafor@lwa.edu.ng`.

| Page | Expected functionality |
|------|------------------------|
| `staff.html` | Teacher dashboard (my students, today, assignments) |
| `students.html` | Class list with checkboxes / search |
| `attendance.html` | Date + class, mark present/absent/late, mark-all-present |
| `assignments.html` | Create assignment: title, subject, class, due date, instructions |
| `grades.html` | Enter CA / exam scores by class + subject + assessment + session |
| `timetable.html` | Teacher timetable with class/subject filters |
| `materials.html` | Upload PDF/DOC/PPT/MP4 up to 20MB; subject + class |
| `messages.html` | Compose to parent / staff / admin office |
| `announcements.html` | Title, category (Academic/Event/General/Urgent), audience (staff scopes), body |
| `profile.html` | First name, last name, email, phone |
| `settings.html` | Account / notification preferences (UI only) |

### B5. Parent portal (`parent_student/`, entered via `Parent_studentlogin.html`)

Parent can switch children (mock: Chiamaka / David).

| Page | Expected functionality |
|------|------------------------|
| `dashboard.html` | Parent home |
| `children.html` | Linked children: class teacher, term, average, attendance, position |
| `academics.html` | Term results: subject, CA, exam, total, grade, remark; download result |
| `assignments.html` | Child assignments |
| `attendance.html` | Monthly attendance by child |
| `timetable.html` | Child timetable |
| `materials.html` | Download learning materials |
| `fees.html` | Breakdown, pay outstanding, print statement, payment history, receipts |
| `announcements.html` | School notices |
| `messages.html` | School messages |
| `profile.html` | Parent profile |
| `settings.html` | Preferences |

### B6. Student portal (`parent_student/`, entered via `parent_studentPage.html`)

Shares many pages with the parent portal. Distinct home: `student_dashboard.html`. Logout returns to `parent_studentPage.html`. Mock student: **Chiamaka Nwosu**.

Student-visible modules: dashboard, profile, academics, assignments, attendance, fees, timetable, materials, messages, announcements, settings.

### B7. Forms and the data they collect

**Admission application (`admissions.html`)** — currently builds a mailto body; files are not attached.

- Admission: session, level (Nursery/Primary/Secondary), class applied, entry term
- Pupil: surname, first name, other names, sex, DOB, nationality, state of origin, LGA, address, previous school, last class
- Parent: full name, relationship (Father/Mother/Guardian), phone, email, occupation, alternative phone, address
- Health: blood group, genotype, allergies/conditions, interests
- Files: passport photo (image), birth certificate (pdf/image), exam fee receipt (pdf/image)
- Declaration checkbox

**Contact (`contact.html`)** — mailto.

- name, phone, email, subject (admission / visit / academic / fees / general), message

**Admin session form:** year name, start date, end date, term count.

**Admin fee form:** admission number, amount, channel, note.

**Admin announcement form:** title, audience, body.

**Admin settings form:** school name, address, phone, email.

**Staff assignment form:** title, subject, class, due date, instructions.

**Staff material upload:** file, subject, class.

**Staff profile:** first name, last name, email, phone.

**Staff message:** recipient, subject, body.

**Staff announcement:** title, category, audience, body.

**Staff grades:** class, subject, assessment (First CA / Second CA / Examination / Final Result), session, per-student scores.

**Staff attendance:** date, class, per-student status.

### B8. Assets and reusable pieces

| Location | Contents |
|----------|----------|
| `Image/` | Logo, campus photos, founders, sports, classrooms (referenced throughout) |
| `CSS/` | `index.css`, `branches.css`, `admin-auth.css`, `admin-command.css`, `staff.css`, `parent_student.css`; leftover unused `admin.css`, `classes.css` |
| `JS/` | `nav.js` (public menu), `admin-auth.js`, `admin-command.js` |
| CDN | Bootstrap 5.3, Bootstrap Icons, Google Fonts |

There is **no shared component library**. Sidebars are copy-pasted into every portal page.

### B9. Hard-coded / mock data currently used

All portal numbers and people are decorative:

- Pupils on roll: 1,248 (active 1,216 / pending 18 / inactive 14)
- Teaching staff: 86
- Forms: 42
- Fees: expected ₦62.4M, collected ₦48.6M, outstanding ₦13.8M
- Attendance: 94.2%
- Students: Chiamaka Okafor `SRS/2025/0142` JSS 2A; Daniel Okoro; Adaeze Nwosu; Emmanuel Kalu; Olivia Iheanacho; Chiamaka Bennie `SRS/2026/0241`; Chiamaka Nwosu; David Nwosu / David Bennie
- Staff: Mrs. Eze, Mr. Daniel Okoro, Mrs. Cynthia Obi, Mr. Alex Obi, Mrs. Grace Ade, Mrs. Amaka, Mrs. Mary Okafor
- Greeting hard-coded to “Mrs. Ibeaja” in admin JS
- Fee items: Tuition ₦180,000, ICT ₦25,000, Development Levy ₦50,000, Learning Materials ₦30000
- Result columns: CA (out of ~30) + Exam (out of ~70) = total, letter grade, remark
- Payment channels: Transfer, Cash, POS
- No payment gateway (Paystack/Flutterwave) is wired; parent page has a “Pay Outstanding Balance” button with no processor

### B10. Frontend database assumptions (inferred, not implemented)

- Admission numbers like `SRS/{sessionYear}/{sequence}`
- Staff IDs like `SRS/TCH/{sequence}`
- Forms named `{Level} {number}{arm}` e.g. `JSS 2A`, `Primary 4B`, `Nursery 2`
- Levels: Nursery, Primary, JSS, SS
- Sessions: `YYYY/YYYY`
- Three terms (sometimes two)
- One operational campus: Owerri
- Fee states: Paid / Partial / Outstanding (Unpaid)
- Student status: Active / Pending / Inactive
- Staff status: Active / On leave
- Attendance: Present / Absent / Late
- Assessments: First CA, Second CA, Examination, Final Result
- Parent may have multiple children
- Class has a class teacher and a capacity (e.g. 32 / 35)

---

## C. Existing Laravel modules

Laravel is a **stock skeleton**. Inspected and confirmed absent unless listed.

| Area | Status |
|------|--------|
| Models | `User` only (`name`, `email`, `password`) |
| Controllers | abstract `Controller` only |
| Middleware | none custom; `bootstrap/app.php` empty middleware callback |
| Routes | `GET /` welcome; `GET /up` health; `routes/console.php` inspire command |
| `routes/api.php` | **does not exist**; API routing not registered |
| Services | none |
| Policies | none |
| Form requests | none |
| API resources | none |
| Enums | none |
| Notifications | trait on User only; no notification classes |
| Mailables | none |
| Jobs | default job tables only; no job classes |
| Events / listeners | none |
| Seeders | `DatabaseSeeder` (test user) |
| Factories | `UserFactory` |
| Tests | default `ExampleTest` (GET `/` returns 200; unit true===true) |
| Auth packages | **no** Breeze, Fortify, Jetstream, Sanctum, Passport, Socialite |
| Permission packages | **no** Spatie Permission |
| Auth config | default `web` session guard, eloquent `User` |

`app/` contains only:

- `Http/Controllers/Controller.php`
- `Models/User.php`
- `Providers/AppServiceProvider.php`

---

## D. Required backend modules

Only modules justified by the existing frontend (or required to support it securely). Items in the original brief that have **no UI** are listed as deferred.

### Implement (frontend exists)

1. Authentication (four portals + missing admin login)
2. Roles and authorization
3. User management
4. School settings
5. Academic sessions and terms
6. Levels, classes, class arms/sections
7. Subjects and class–subject–teacher assignment
8. Departments (staff filters)
9. Students
10. Parents/guardians and parent–child links
11. Teachers / teaching staff
12. Student enrollment (class placement per session)
13. Attendance
14. Assessments and results
15. Timetables
16. Assignments
17. Learning materials (uploads)
18. Announcements
19. Internal messages
20. Admissions applications + documents
21. Contact enquiries
22. Fee types, structures, invoices, payments, receipts/statements
23. Dashboard statistics
24. Reports (enrolment, fees, attendance, staff)
25. Profiles / account settings
26. Audit log (not in UI, but required for fees, results, role changes)

### Defer (not in current UI, or only implied)

| Module | Why deferred |
|--------|----------------|
| Distinct Principal / Vice Principal / Accountant portals | No pages |
| Student promotion workflow | Session archive copy says “roll transferred”; no promotion screen |
| Full printable report-card designer | Academics has “Download Result”; treat as PDF of term results, not a separate designer |
| Online payment gateway | “Pay Outstanding Balance” exists; no processor, keys, or callback UI |
| Alumni | Nav link is `#` |
| PTA management | `pta.html` unfinished |
| Multi-campus operations | `branches.html` is marketing (Nursery/Primary/Secondary), not extra campuses |
| Boarding houses | “House” in admin copy is literary; no house register |
| Exams as a separate scheduling module | Grades use CA/Exam assessments; no exam timetable UI beyond notices |

---

## E. Frontend-to-backend integration points

Integration rule: **do not replace HTML/CSS**. Add JavaScript `fetch` calls and replace mock tables with rendered API data.

### Auth

| Frontend | Backend |
|----------|---------|
| `superAdminLogin.html` | `POST /api/v1/auth/login` (role `super_admin`; optional clearance key) |
| missing `adminLogin.html` | Same login endpoint; role `school_admin` |
| `staffLogin.html` | Same login; roles `teacher` / `staff` |
| `Parent_studentlogin.html` | Login by email **or** linked admission number; role `parent` |
| `parent_studentPage.html` | Login by admission number; role `student` |
| Logout links | `POST /api/v1/auth/logout` |
| Forgot password | `POST /api/v1/auth/forgot-password` (pages currently `#`) |

### Public

| Frontend | Backend |
|----------|---------|
| `admissions.html` `#applicationForm` | `POST /api/v1/public/admissions` (multipart) |
| `contact.html` `#contactForm` | `POST /api/v1/public/contact` |

### Admin

| Frontend | Backend |
|----------|---------|
| `admin/dashboard.html` | `GET /api/v1/admin/dashboard` |
| `admin/students.html` | Students CRUD + filters |
| `admin/teachers.html` | Staff CRUD + filters |
| `admin/classes.html` | Classes / sections CRUD |
| `admin/academic_sessions.html` | Sessions + terms |
| `admin/timetable.html` | Timetable by section |
| `admin/fees.html` | Record payment; recent payments |
| `admin/announcements.html` | Announcements CRUD |
| `admin/messages.html` | Enquiries + internal messages |
| `admin/reports.html` | Report endpoints |
| `admin/settings.html` | School settings; admin user list |

### Staff

| Frontend | Backend |
|----------|---------|
| Dashboard / my students | Scoped to assigned class/subjects |
| Attendance | Save daily marks |
| Grades | Save assessment scores |
| Assignments | CRUD + submissions later if UI appears |
| Materials | Multipart upload |
| Messages / announcements / profile | Authenticated CRUD |

### Parent / student

| Frontend | Backend |
|----------|---------|
| Children list | Guardianship |
| Academics | Own / child results only |
| Fees | Own / child invoices + payments |
| Attendance, timetable, materials, assignments, announcements, messages | Ownership-scoped reads |

---

## F. Database entities required

See `DATABASE_ARCHITECTURE.md` for columns, types, indexes, and FKs.

Proposed core entities:

- `users`, `roles`, `role_user`
- `staff_profiles`, `student_profiles`, `guardian_profiles`, `guardian_student`
- `school_settings`, `campuses`
- `academic_sessions`, `terms`
- `levels`, `school_classes`, `class_sections`
- `departments`, `subjects`, `class_subject_teacher`
- `enrollments`, `promotions` (history table; UI later)
- `attendance_records`
- `assessment_types`, `assessment_scores`, `term_results`
- `timetable_slots`
- `assignments`, `assignment_submissions`
- `learning_materials`
- `announcements`
- `conversations`, `messages`
- `notifications`
- `admission_applications`, `documents`
- `contact_enquiries`
- `fee_types`, `fee_structures`, `invoices`, `invoice_items`, `payments`, `payment_allocations`
- `audit_logs`

---

## G. Authentication requirements

Laravel 13 recommended approach **for this project** (same-origin static HTML + JSON API):

1. Keep the built-in **`web` session guard**. Do not add a second auth system.
2. Register `routes/api.php` under `/api` **and** use the `web` middleware group (session + CSRF) so cookie auth works for the existing HTML.
3. **Do not install Sanctum yet.** Sanctum is justified only if the frontend is hosted on another origin. Same-origin session cookies are enough.
4. Do not install Breeze/Jetstream; they would generate Blade/Vue auth screens and fight the existing HTML.
5. Passwords hashed with Laravel’s `hashed` cast.
6. Super Admin login may require an additional `clearance_key` (hashed server-side, stored in school settings or env — **not** a second password column on every user).
7. “Remember me” → Laravel remember token.
8. Password reset tokens already have a table; wire email later (`MAIL_MAILER` is currently `log`).
9. Never trust `sessionStorage` or frontend role checks. `admin-command.js` must be replaced with a server session.

Login identifiers:

| Portal | Identifier |
|--------|------------|
| Super Admin / Admin / Staff | email + password |
| Student | admission number + password |
| Parent | email + password **preferred**; frontend currently uses admission number — backend should resolve admission number → linked guardian user |

A parent and a student must **not** share one password by default. Linking is via `guardian_student`.

---

## H. User roles and permissions

### Roles evidenced by the frontend (implement now)

| Role | Portal | Notes |
|------|--------|-------|
| `super_admin` | Admin command desk via `superAdminLogin.html` | “Sovereign”; Mrs. Ibeaja in mock UI; extra clearance key |
| `school_admin` | Admin command desk via missing `adminLogin.html` | Daily ledger / office |
| `teacher` | Staff portal | Class teacher + subject teacher |
| `parent` | Parent portal | One or more children |
| `student` | Student portal | Own records only |

### Roles to **seed as data** but not build separate UIs yet

`principal`, `vice_principal`, `accountant`, `staff` (non-teaching)

These can map to existing portals later (`accountant` → fees subset of admin; `staff` → limited staff portal).

### Authorization model

- Custom `roles` + `role_user` (no Spatie package unless granularity later demands it).
- Laravel **policies** for every resource.
- Middleware `role:super_admin,school_admin` etc.
- Ownership checks: teacher sees assigned sections/subjects; parent sees linked children; student sees self.
- IDOR rule: changing an ID in a URL/API must 403, not 404 of another pupil’s result or invoice.

---

## I. API requirements

- Version prefix: `/api/v1`
- Auth: session cookie + CSRF (`X-XSRF-TOKEN`)
- Success shape:

```json
{
  "success": true,
  "message": "Student created successfully.",
  "data": {}
}
```

- Validation error shape: Laravel JSON validation (`message` + `errors` object), optionally wrapped consistently in an exception renderer.
- Pagination: Laravel length-aware paginator inside `data`.
- Do not expose: `password`, `remember_token`, clearance hashes, internal notes unless authorized.
- Use API Resources.
- File uploads: `multipart/form-data` for admissions, materials, photos.

No existing API endpoints were found.

---

## J. Admin requirements

Admin must be able to:

- View dashboard counts from the database
- Register / update / deactivate students
- Appoint / update teaching staff
- Manage sessions, terms, classes, arms, subjects
- Record fee payments (cash/transfer/POS)
- Publish announcements
- Triage contact/admission messages
- Update school identity settings
- Open reports

Missing before integration: **`adminLogin.html`**. The public site and all admin logout links point to it. It should be created as a sibling of `superAdminLogin.html` without replacing that page.

---

## K. Validation requirements

Server-side Form Requests for every write.

Examples grounded in the UI:

- Unique `email` on users; unique `admission_number` on students; unique `staff_number` on staff
- Admission application: required personal + parent fields; files mime/size
- Dates: session `starts_on` < `ends_on`; term dates inside session
- Enrollment: one active enrollment per student per session
- Scores: numeric, within assessment max (CA vs exam)
- Payments: amount > 0; cannot exceed remaining invoice balance without an explicit overpayment rule
- Attendance: one record per student per date per section
- Class capacity: warn or block when roll >= capacity
- Role assignment: only `super_admin` can create another `super_admin`

Never rely on HTML `required` or disabled buttons.

---

## L. Security requirements

| Risk today | Mitigation |
|------------|------------|
| Admin “auth” is sessionStorage | Server sessions + CSRF |
| Staff/parent/student login ignores password | Credential verification |
| Missing `adminLogin.html` | Create login; do not leave portal open |
| IDOR on student/result/fee IDs | Policies + query scoping |
| Mass assignment | `$fillable` / `Fillable` attributes |
| File upload abuse | MIME, size, stored outside web root for private docs |
| Privilege escalation | Role middleware + policies |
| Result/payment tampering | Audit log + transactions; teachers cannot edit another class |
| SQL injection | Eloquent / query builder only |
| APP_DEBUG true | Keep local only |
| Hard-coded emails in UI | Settings table |

---

## M. File / media upload requirements

| Source | Files | Visibility |
|--------|-------|------------|
| Admissions | passport photo, birth cert, exam receipt | Private (`local` disk) |
| Student profile | passport photo | Private; signed/authorized URL |
| Learning materials | PDF, DOC, PPT, MP4, max 20MB (staff UI) | Authorized to enrolled students/parents of that class |
| Assignment attachments | if added later | Private |

Do not store uploads in `public/` unless they are truly public (logo). School logo can use the `public` disk.

---

## N. Notification / email requirements

Current mailer is `log`. Real SMTP is not configured.

Needed eventually:

- Admission application received (office + parent)
- Contact enquiry received
- Password reset
- Announcement optional email
- Fee receipt email
- Payment recorded

In-app `notifications` table should back the bell icons on staff/parent/student portals.

Do not implement a third-party mail provider until SMTP credentials exist.

---

## O. Reporting requirements

From `admin/reports.html` and dashboards:

- Enrolment by form, level, status
- Fee collection: expected / posted / outstanding (term + session)
- Staff appointments / leave
- Session close / archive
- Attendance % (admin + staff + parent)
- Academic averages and class position (parent/student academics)

Export: parent “Download Result” / “Print Statement”; staff “Export Results”. First implementation can be HTML/PDF later; CSV is acceptable for admin reports in an early phase.

---

## P. Payment requirements

**In scope (matches UI):**

- Admin posts a payment against an admission number (cash / transfer / POS)
- Parent sees invoice breakdown, paid, balance, history, print statement
- Receipts with references (mock `FEE-881`)

**Out of scope until explicitly approved:**

- Paystack / Flutterwave / card checkout
- Automated bank reconciliation

Fee types evidenced: Tuition, ICT Fee, Development Levy, Learning Materials.

---

## Q. Missing functionality

### Missing files

- `adminLogin.html` (referenced everywhere; does not exist)

### Missing backend (everything school-related)

No school models, migrations, APIs, policies, or real auth.

### Missing / unfinished frontend (do not invent UI)

- `pta.html` incomplete
- Alumni `#`
- Forgot password pages
- Admin “Register a pupil” / “Appoint a master” have **no modal forms** — only buttons. Backend can exist; frontend forms must be added later **without redesigning the page chrome**
- Parent “Pay Outstanding Balance” has no gateway
- Staff/parent/student forms `preventDefault` and do not persist

### Data leftovers

- `mary.okafor@lwa.edu.ng` in staff profile
- Folder name `LWA_S` vs brand Supreme Reagan Schools

---

## R. Potential technical risks

1. **Two roots.** Frontend lives outside the Laravel repo. Integration must not delete `LWA_S`. Serving strategy must be decided (copy into `public/` vs reverse proxy).
2. **Replacing UI accidentally.** Portals are large static HTML. Integration must patch JS/data binding only.
3. **Fake auth is worse than none.** sessionStorage can be forged in DevTools; admin desk is currently open to anyone who sets the key **or** who opens `admin/dashboard.html` after visiting login.
4. **Parent login by admission number** can collide if two guardians share a child; need a defined rule (primary guardian vs all linked guardians).
5. **SQLite in production** is not appropriate for concurrent school use. Stay on SQLite for local development; plan MySQL/MariaDB before go-live. Do not migrate destructively now.
6. **Result history.** Scores must be tied to session + term + assessment so old years remain immutable after a session is archived.
7. **Fee overpayment / partial allocation** across several fee lines (parent UI shows per-line paid/balance).
8. **N+1** on class lists and grade grids (32+ students × many subjects).
9. **File storage** on Windows local disk vs later production Linux/S3.
10. **Installing Breeze/Sanctum/Spatie too early** would duplicate auth and fight the HTML.
11. **Hard-coded greeting and counts** will look “live” while remaining fake until API-wired.
12. **Legal/privacy:** student health fields (blood group, genotype, allergies) are sensitive; restrict to admin and authorized medical/office roles.

---

## S. Recommended implementation order

See also `IMPLEMENTATION_ROADMAP.md`.

**This phase (complete): audit + schema docs only.**

Do **not** create school migrations until this document and `DATABASE_ARCHITECTURE.md` are reviewed.

Proposed next phases:

1. Foundation (safe): APP_NAME, register API routes, API response helpers, enums — no school tables yet if review is pending
2. Auth + roles + policies
3. School settings
4. Academic sessions, terms, levels, classes, sections, subjects
5. Students, guardians, teachers, enrollment
6. Wire login pages (including create `adminLogin.html`)
7. Attendance, assessments, results
8. Fees and payments
9. Admissions + contact (replace mailto)
10. Announcements, messages, materials, assignments, timetables
11. Dashboards and reports
12. Tests (`php artisan test`) and audit log

---

## Inspection evidence (commands and files)

Inspected, not modified:

- `composer.json`, `.env.example`, `.env` (non-secret keys only)
- `php -v`, `php artisan --version`, `php artisan about`, `php artisan migrate:status`
- `app/`, `routes/`, `database/`, `config/`, `bootstrap/app.php`, `tests/`
- Entire `LWA_S` HTML/CSS/JS tree listed and sampled

Not claimed as implemented: any school backend module.
