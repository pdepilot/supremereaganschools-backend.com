# Supreme Reagan Schools — Backend Implementation Log

This file is updated after each major phase. Entries must match work actually done.

---

## Phase 0 — Full project audit (25 August 2026)

### What was implemented

Nothing in application code. Documentation only.

### Inspection performed

Laravel project `C:\Laravel-Projects\supreme-reagan-schools`:

- Laravel 13.26.1, PHP 8.3.32, Composer 2.10.1
- Default database connection is MySQL via `DB_*` environment variables; run migrations against the configured MySQL database
- App code is a skeleton: `User` model, empty `Controller`, welcome route
- No API routes, policies, form requests, school models, Sanctum, Breeze, or Spatie

Frontend `C:\Users\ADMIN\OneDrive\Desktop\LWA_S`:

- Static public site + admin command desk + staff portal + parent/student portal
- No `fetch`/API calls
- Auth is fake (`sessionStorage` or GET form navigation)
- `adminLogin.html` is linked but **missing**
- Admissions and contact use `mailto:`
- All dashboards use hard-coded mock data

### Files created

| File | Location |
|------|----------|
| `BACKEND_IMPLEMENTATION_AUDIT.md` | Laravel project root |
| `DATABASE_ARCHITECTURE.md` | Laravel project root |
| `IMPLEMENTATION_ROADMAP.md` | Laravel project root |
| `BACKEND_IMPLEMENTATION_LOG.md` | Laravel project root (this file) |

### Files modified

None.

### Migrations created

None.

### Routes created

None.

### Models created

None.

### Tests created

None.

### Commands executed (read-only)

- `php -v`
- `php artisan --version`
- `php artisan about`
- `php artisan migrate:status`
- Directory listings and file reads of Laravel `app`, `routes`, `database`, `config`, and the `LWA_S` HTML/CSS/JS tree

### Test results

Not run this phase (`php artisan test` not executed). Default skeleton tests were read, not executed.

### Remaining work

Entire backend: foundation, auth, academic structure, people, attendance, results, fees, admissions, communication, dashboards, tests.

### Known issues

- Frontend and Laravel are disconnected
- `adminLogin.html` does not exist
- `APP_NAME` is still `Laravel`
- Mailer is `log`; no SMTP
- SQLite is fine for local work only
- Staff profile mock email still uses `lwa.edu.ng`
- Admin JS greeting is hard-coded to Mrs. Ibeaja
- Parent login currently uses admission number, not email

### Decision required before Phase 1

Review audit + schema. Do not run school migrations until approved.

---

## Phase 1 — Laravel foundation (25 August 2026)

### What was implemented

Laravel application foundation only: renamed the app, registered `/api/v1`, added a JSON response helper, standardized API validation/404 JSON, and added the approved string-backed enums. No school migrations, models, authentication, roles, or frontend work.

Default Laravel migrations were inspected and left unchanged:

- `0001_01_01_000000_create_users_table`
- `0001_01_01_000001_create_cache_table`
- `0001_01_01_000002_create_jobs_table`

SQLite remains the local database. No packages were installed.

### Files created

- `app/Support/ApiResponse.php`
- `app/Enums/UserStatus.php`
- `app/Enums/RoleSlug.php`
- `app/Enums/Gender.php`
- `app/Enums/GuardianRelationship.php`
- `app/Enums/StudentStatus.php`
- `app/Enums/StaffStatus.php`
- `app/Enums/EnrollmentStatus.php`
- `app/Enums/SessionStatus.php`
- `app/Enums/AttendanceStatus.php`
- `app/Enums/AssessmentKind.php`
- `app/Enums/AnnouncementAudience.php`
- `app/Enums/AnnouncementCategory.php`
- `app/Enums/AnnouncementStatus.php`
- `app/Enums/FeeChannel.php`
- `app/Enums/InvoiceStatus.php`
- `app/Enums/PaymentStatus.php`
- `app/Enums/ApplicationStatus.php`
- `app/Enums/EnquiryStatus.php`
- `app/Enums/DocumentType.php`
- `routes/api.php`
- `routes/api/v1.php`
- `tests/Unit/ApprovedEnumsTest.php`
- `tests/Feature/ApiFoundationTest.php`

### Files modified

- `.env` — `APP_NAME` only
- `.env.example` — `APP_NAME` only
- `config/app.php` — default `APP_NAME` fallback
- `bootstrap/app.php` — register API routes; JSON exception envelopes
- `phpunit.xml` — testing `APP_NAME`
- `BACKEND_IMPLEMENTATION_LOG.md` — this phase

### Migrations created

None.

### Routes created

- `GET /api/v1/health` (`v1.health`)

`GET /up` remains the Laravel health endpoint.

### Models created

None.

### Tests created

- `tests/Unit/ApprovedEnumsTest.php`
- `tests/Feature/ApiFoundationTest.php`

Existing skeleton tests were kept.

### Commands executed

- `php -v`
- `php artisan --version`
- `php artisan about --only=environment`
- `php artisan route:list --path=api`
- `php artisan test`

### Test results

`php artisan test` — **7 passed** (37 assertions). Duration ~606 ms.

### Remaining work

Phase 3: school settings and academic structure. Then people/enrollment. Do not start until Phase 2 is reviewed.

### Known issues / decisions for Phase 2

Completed in Phase 2. See the Phase 2 log entry below.

---

## Phase 2 — Authentication and authorization (25 August 2026)

### What was implemented

Web-session authentication on Laravel’s existing `web` guard. Relational roles (`roles` + `role_user`). Role middleware. Login/logout with session regeneration, CSRF on web auth routes, and rate limiting of failed attempts. Missing `adminLogin.html` created in the existing admin visual language. Frontend copied into Laravel without putting protected admin HTML in `public/`.

No Breeze, Sanctum, Fortify, Jetstream, or Spatie. No school-domain tables (students, classes, fees, etc.). Student admission-number login is structurally accepted and always rejected until `student_profiles` exists.

### Authentication UI inspection (LWA_S)

- Admin login was missing (`adminLogin.html` linked everywhere).
- Super admin login (`superAdminLogin.html`) is the main admin entry; it posts to Laravel `POST /login` (clearance key is visual only).
- Staff / parent / student logins used GET forms with no credential check.
- Admin dashboards gated only by `sessionStorage.srsCommand` (replaced with Laravel logout + server auth for Laravel-served admin pages).
- No hard-coded passwords were found. Placeholders (e.g. `ibeajaezinne@gmail.com`) are not credentials.

### Files created (Laravel)

- `app/Enums/AuthPortal.php`
- `app/Models/Role.php`
- `app/Models/Concerns/HasRoles.php`
- `app/Services/AuthenticationService.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Http/Resources/UserResource.php`
- `app/Http/Middleware/EnsureUserHasRole.php`
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- `app/Http/Controllers/Auth/AdminPortalController.php`
- `app/Support/FrontendPage.php`
- `routes/auth.php`
- `database/migrations/2026_08_25_170000_add_status_to_users_table.php`
- `database/migrations/2026_08_25_170100_create_roles_table.php`
- `database/migrations/2026_08_25_170200_create_role_user_table.php`
- `database/seeders/RoleSeeder.php`
- `database/seeders/LocalAdminSeeder.php`
- `tests/Feature/AuthenticationTest.php`
- `public/site/` (marketing pages, CSS, JS, images)
- `public/site/README.txt`
- `resources/frontend/superAdminLogin.html`
- `resources/frontend/admin/*.html`

### Files created (LWA_S source)

- (operations-desk `adminLogin.html` was later removed; sovereign login is the only admin login)

### Files modified

- `app/Models/User.php`
- `database/factories/UserFactory.php`
- `database/seeders/DatabaseSeeder.php`
- `bootstrap/app.php`
- `routes/web.php`
- `tests/Feature/ExampleTest.php`
- `BACKEND_IMPLEMENTATION_LOG.md`
- LWA_S `JS/admin-command.js` (Laravel logout; removed sessionStorage gate)
- LWA_S `CSS/admin-auth.css` (error/loading styles already implied by `auth-admin`)

### Migrations created

- `add_status_to_users_table` (non-destructive)
- `create_roles_table`
- `create_role_user_table`

### Seeders created

- `RoleSeeder` — all approved roles
- `LocalAdminSeeder` — local `admin@supremereaganschools.test` with school_admin (password `password`)

### Routes created

- `GET /portal/login` (`login`) — sovereign page (`superAdminLogin.html`)
- `POST /login` (`login.store`)
- `POST /logout` (`logout`)
- `GET /portal/home` (`portal.home`)
- `GET /staff/home` (`staff.home`)
- `GET /parent/home` (`parent.home`)
- `GET /student/home` (`student.home`)
- `GET /site/portal/{page}` (auth + school-office roles)
- `GET /site/admin/{page}` → redirect to `portal.page`
- `GET /` (`home`) — public `index.html`
- `GET /site/{page}` — public marketing HTML
- `GET /site/superAdminLogin.html` → redirect to `login`
- `GET /site/adminLogin.html` → redirect to `login`
- `GET /admin/login` → redirect to `login`
- `GET /admin/home` → redirect to `portal.home`
- `GET /admin/office-login` → redirect to `login`

`/api/v1` remains stateless. CSRF is not applied to the API group.

### Models created

- `Role` (plus `User` extended)

### Tests created

- `tests/Feature/AuthenticationTest.php`

### Commands executed

- Frontend copy via `robocopy` into `public/site` and `resources/frontend`
- `php artisan migrate --force`
- `php artisan db:seed --force`
- `php artisan route:list --except-vendor`
- `php artisan test`

### Test results

`php artisan test` — **23 passed** (109 assertions).

### Security checks performed

- Session regenerated after login; invalidated + CSRF rotated on logout
- Passwords hashed; omitted from JSON resources
- Failed logins use one generic message (including wrong portal and inactive users)
- Failed-attempt rate limiting (5 / minute / identifier+IP)
- Role checks are server-side (`EnsureUserHasRole` + `RoleSlug`)
- Admin HTML is not in `public/site/admin/` (avoids static-file auth bypass under `artisan serve`)
- Login redirect target is a named internal route, not request input
- Remember-me uses Laravel’s remember token
- No second auth system; no CSRF disabled; no wildcard CORS
- Student admission login cannot succeed until student profiles exist

### Known limitations

- Staff/parent/student HTML shells in `public/site/` are not Laravel-auth-protected (HTML only; APIs will be the security boundary)
- Student login by admission number is not functional yet (no `student_profiles`)
- Admin dashboard greeting is still hard-coded “Mrs. Ibeaja”
- Local seeder admin password is `password` (local only)
- Mailer still `log`

### Remaining work

Phase 3: school settings and academic structure. Do not start until requested.

### Correction — main admin login is the sovereign page (25 August 2026)

`GET /admin/login` serves `superAdminLogin.html` as the only admin login. `/admin/office-login` and leftover `adminLogin.html` paths redirect there. The operations-desk page was removed. The clearance key remains on the sovereign form visually and is not checked by the server. `public/site/superAdminLogin.html` was removed so `artisan serve` cannot bypass Laravel.

The public-site control is labelled **Portal** and opens `/portal/login`. `/admin/login` redirects there. The sovereign page remains the login UI.

---

## Phase 3 — Core school structure and academic foundation (25 August 2026)

### What was implemented

School identity and the academic catalogue that later modules will depend on. SQLite unchanged. No students, parents, teachers, attendance, assessments, results, fees, admissions, timetable, assignments, messaging, or reports.

Authorization uses Phase 2 roles. Academic writes are limited to `super_admin` and `school_admin` via `role:` middleware plus `AcademicStructurePolicy`.

Academic API routes use `web` + `auth` so the existing portal session and CSRF cookie work from the command desk.

### Frontend vs schema decisions

- Four levels (Nursery, Primary, Junior Secondary, Senior Secondary). The Setup page mock “3 levels” was copy, not structure.
- Class names are year-groups (`JSS 1`). Arms live on `class_sections` (`A` / `B` / empty). Display names such as `JSS 1 A` are derived, not stored as `JSS 1A` class rows.
- UI-facing copy uses **Arm**. Database table is `class_sections`.
- Terms are configurable (2 or 3) via `term_count`. Creating a session seeds First/Second/(Third) Term as `planned`.
- Current session/term live on `school_settings`, not on an `is_current` column on terms.
- Subjects are reusable. Offerings attach a subject to a class-section offering (section + session + campus).
- `class_teacher_id` and `class_subject_teacher` were not created; they need `staff_profiles` in Phase 4.
- Sovereign clearance key is still not stored.

### Files created

**Migrations**

- `database/migrations/2026_08_25_180000_create_campuses_table.php`
- `database/migrations/2026_08_25_180100_create_levels_table.php`
- `database/migrations/2026_08_25_180200_create_school_classes_table.php`
- `database/migrations/2026_08_25_180300_create_class_sections_table.php`
- `database/migrations/2026_08_25_180400_create_academic_sessions_table.php`
- `database/migrations/2026_08_25_180500_create_terms_table.php`
- `database/migrations/2026_08_25_180600_create_school_settings_table.php`
- `database/migrations/2026_08_25_180700_create_departments_table.php`
- `database/migrations/2026_08_25_180800_create_subjects_table.php`
- `database/migrations/2026_08_25_180900_create_class_section_offerings_table.php`
- `database/migrations/2026_08_25_181000_create_subject_offerings_table.php`

**Models**

- `Campus`, `Level`, `SchoolClass`, `ClassSection`, `AcademicSession`, `Term`, `SchoolSetting`, `Department`, `Subject`, `ClassSectionOffering`, `SubjectOffering`

**Services**

- `AcademicSessionService`, `TermService`, `SchoolSettingService`

**HTTP**

- Controllers under `app/Http/Controllers/Api/V1/`
- Form requests under `app/Http/Requests/Academic/`
- Resources under `app/Http/Resources/Academic/`
- Policy `AcademicStructurePolicy`
- Routes `routes/api/v1/academic.php`

**Seeders**

- `CampusSeeder`, `LevelSeeder`, `SchoolClassSeeder`, `AcademicSessionSeeder`, `SubjectSeeder`, `SchoolSettingSeeder`, `ClassSectionOfferingSeeder`, `AcademicStructureSeeder`

**Frontend**

- `public/site/JS/portal-structure.js`
- LWA_S `JS/portal-structure.js`
- Hooks on existing Setup, Session, and Classes pages (Laravel `resources/frontend/admin/` and LWA_S `admin/`)

**Tests**

- `tests/Concerns/CreatesAcademicContext.php`
- `tests/Feature/Academic/SchoolSettingApiTest.php`
- `tests/Feature/Academic/AcademicSessionApiTest.php`
- `tests/Feature/Academic/TermApiTest.php`
- `tests/Feature/Academic/LevelClassSectionApiTest.php`
- `tests/Feature/Academic/SubjectOfferingApiTest.php`
- `tests/Feature/Academic/AcademicAuthorizationTest.php`
- `tests/Feature/Academic/AcademicDatabaseIntegrityTest.php`
- `tests/Feature/Academic/PortalAcademicPagesTest.php`

### Files modified

- `app/Http/Controllers/Controller.php` — `AuthorizesRequests`
- `app/Providers/AppServiceProvider.php` — policy map, `JsonResource::withoutWrapping()`
- `bootstrap/app.php` — JSON 403 envelope
- `database/seeders/DatabaseSeeder.php`
- `phpunit.xml` — `DB_FOREIGN_KEYS=true`
- `routes/api/v1.php`
- `DATABASE_ARCHITECTURE.md`
- `IMPLEMENTATION_ROADMAP.md`
- `BACKEND_IMPLEMENTATION_LOG.md`
- Admin HTML: `settings.html`, `academic_sessions.html`, `classes.html`

### Routes created (`/api/v1`, session auth, `super_admin` / `school_admin`)

- `GET|PUT school-settings`
- `apiResource academic-sessions` + `POST academic-sessions/{academic_session}/activate`
- nested terms; `PUT|DELETE terms/{term}`
- `apiResource campuses` (no show)
- `GET departments`
- `apiResource levels` (no show)
- `apiResource classes` (parameter `school_class`)
- nested arms `classes/{school_class}/sections`; `PUT|DELETE sections/{class_section}`
- `apiResource subjects` (no show)
- `apiResource class-section-offerings` (no show)
- `apiResource subject-offerings` (no show, no update)

### Seed data (idempotent)

- Campus: Owerri
- Levels: Nursery, Primary, Junior Secondary, Senior Secondary
- Classes: Nursery 1–2; Primary 1–6; JSS 1–3; SS 1–3
- Arms: empty for Nursery; A/B for Primary/JSS/SS
- Sessions: 2024/2025 archived, 2025/2026 active, three terms each
- Departments and subjects from the frontend mocks plus a small catalogue
- School settings from the Setup page
- Class-section offerings + subject offerings for the active session

No fake students, teachers, parents, results, payments, or attendance.

### Commands executed

- `php artisan test`
- `php artisan migrate --force`
- `php artisan db:seed --force`
- `php artisan migrate:status`
- `php artisan route:list --path=api/v1 --except-vendor`

### Test results

`php artisan test` — **66 passed** (470 assertions).

### Database integrity

- Parent academic rows use `restrictOnDelete`
- User FKs (`created_by`, `updated_by`) use `nullOnDelete`
- Unique constraints on session name, term number/name per session, level name/slug, class name per level, arm per class, offering per section+session, subject offering per offering+subject
- Only one active academic session (service); activating archives the previous active session
- Current term must belong to the current session
- SQLite foreign keys enabled (`DB_FOREIGN_KEYS=true` in tests)

### Authorization

- `super_admin` and `school_admin` may manage Phase 3 structure
- `teacher`, `parent`, and `student` receive 403 on these APIs
- Guests receive 401 Unauthenticated envelope
- School settings are not public

### Deviations from the original schema

- No `super_admin_clearance_key` on `school_settings`
- Added `motto`, `website`, `timezone` on settings
- Terms use `SessionStatus` instead of `is_current`
- Levels gained `description` and `is_active`; names are unique
- `class_section_offerings` uses `is_active` and omits `class_teacher_id`
- `subject_offerings` created instead of `class_subject_teacher` (no staff yet)
- Subjects gained `description`

### Known limitations

- No dedicated admin pages for levels, arms, subjects, departments, or subject offerings (backend only)
- Classes “Open a form” button is not a create wizard
- Class teacher and roll columns show placeholders until Phase 4
- Setup “Desk access” tickets remain mock people data
- Changing `term_count` on an existing session does not add/remove term rows
- A session created through the API always seeds terms, so it cannot be hard-deleted; archive instead

### Remaining work

Phase 4: people and enrollment. Completed below.

---

## Phase 4 — People, relationships, staff assignments & student enrollment (25 August 2026)

### What was implemented

Profile tables on top of `users` (no student/staff/guardian fields dumped onto `users`). Enrollment is the source of truth for academic placement. Class and subject teachers are historical assignment rows, not permanent FKs on classes. Student admission-number login uses the existing `web` guard and the user’s hashed password.

No attendance, assessments, exams, results, report cards, fees, payments, admissions, timetable, assignments, learning materials, messaging, announcements, or reports.

### Tables created

- `staff_profiles`
- `student_profiles`
- `guardian_profiles`
- `guardian_student`
- `enrollments`
- `promotions` (table only; no wizard/CRUD)
- `class_teacher_assignments`
- `subject_teacher_assignments`

### Models created

- `StaffProfile`, `StudentProfile`, `GuardianProfile`, `GuardianStudent`
- `Enrollment`, `Promotion`
- `ClassTeacherAssignment`, `SubjectTeacherAssignment`

`User` gained `staffProfile()`, `studentProfile()`, `guardianProfile()`.

### Relationships

- User 1–1 StaffProfile (required unique `user_id`)
- User 1–1 StudentProfile (nullable unique `user_id`)
- User 1–1 GuardianProfile (nullable unique `user_id`)
- GuardianProfile ↔ StudentProfile via `guardian_student` (many-to-many, unique pair)
- StudentProfile → Enrollment → ClassSectionOffering + AcademicSession
- StaffProfile → ClassTeacherAssignment → ClassSectionOffering
- StaffProfile → SubjectTeacherAssignment → SubjectOffering

### Controllers

- `StaffController`, `StudentController`, `GuardianController`
- `EnrollmentController`
- `ClassTeacherAssignmentController`, `SubjectTeacherAssignmentController`
- `MePeopleController` (`/me/children`, `/me/enrollments`, `/me/students`)

### Services

- `SchoolNumberService`, `PeopleAccessService`
- `StaffService`, `StudentService`, `GuardianService`, `EnrollmentService`
- `ClassTeacherAssignmentService`, `SubjectTeacherAssignmentService`
- `AuthenticationService` now resolves admission numbers for the student portal

### Form Requests / Resources / Policies

- `app/Http/Requests/People/*`
- `app/Http/Resources/People/*`
- Policies for staff, students, guardians, guardian-student, enrollments, both assignment types

### Routes (`/api/v1`)

- `GET/POST /staff`, `GET/PUT/DELETE /staff/{staff_profile}`
- `GET/POST /students`, `GET/PUT/DELETE /students/{student_profile}`
- `GET/POST /guardians`, `GET/PUT/DELETE /guardians/{guardian_profile}`
- `POST /guardians/{guardian_profile}/students`, `DELETE /guardian-students/{guardian_student}`
- `GET/POST /enrollments`, `GET/PUT/DELETE /enrollments/{enrollment}` (DELETE withdraws, does not hard-delete)
- `GET/POST /class-teacher-assignments`, `DELETE /class-teacher-assignments/{class_teacher_assignment}` (ends assignment)
- `GET/POST /subject-teacher-assignments`, `DELETE /subject-teacher-assignments/{subject_teacher_assignment}` (ends assignment)
- `GET /me/children`, `GET /me/enrollments`, `GET /me/students`

Writes require `super_admin` or `school_admin`. Reads use policies.

### Seeders

`PeopleSeeder` (idempotent): Mrs. Eze, Mr. Daniel Okoro, Mrs. Cynthia Obi, Mrs. Grace Ade; pupils Chiamaka Okafor `SRS/2025/0142`, Daniel Okoro `SRS/2025/0198`, Adaeze Nwosu `SRS/2025/0221`; three guardians; enrollments; class/subject teacher assignments.

Local development password for seeded accounts: `password`.

### Frontend integrations (existing pages only)

- Admin Pupils (`resources/frontend/admin/students.html`) — live roll
- Admin Staff (`teachers.html`) — live directory
- Admin Classes — class teacher name + enrollment count
- Student login (`parent_studentPage.html`) — admission number + password
- Parent login (`Parent_studentlogin.html`) — email + password
- Staff login (`staffLogin.html`) — email + password
- Parent My Children (`parent_student/children.html`)
- Staff My Students (`staff/students.html`)
- After login, `/staff/home`, `/parent/home`, `/student/home` serve the existing dashboards

No new register/appoint modals. Copies synced to `LWA_S`.

### Tests created

- `tests/Feature/People/StaffApiTest.php`
- `tests/Feature/People/StudentApiTest.php`
- `tests/Feature/People/GuardianApiTest.php`
- `tests/Feature/People/EnrollmentApiTest.php`
- `tests/Feature/People/AssignmentApiTest.php`
- `tests/Feature/People/PeopleAuthorizationTest.php`
- `tests/Feature/People/StudentAdmissionLoginTest.php`
- `tests/Feature/People/PeopleDatabaseIntegrityTest.php`
- `tests/Feature/People/PortalPeoplePagesTest.php`

### Commands executed

- `php artisan test --compact`
- `php artisan migrate --force`
- `php artisan db:seed --class=PeopleSeeder --force`
- `php artisan migrate:status`

### Test results

`php artisan test --compact` — **104 passed** (637 assertions).

### Authorization / privacy

- Admins manage people, enrollment, and assignments
- Teachers see assigned pupils only; cannot write staff/enrollment/assignments
- Parents see linked children only (IDOR 403)
- Students see their own profile only (IDOR 403)
- Password hashes, remember tokens, and health fields are not serialized to non-admins
- Guests receive 401 on people APIs

### Deviations from the original schema

- `phone` and `photo_path` on `staff_profiles`
- `phone`, `email`, and `photo_path` on `student_profiles`
- No `class_teacher_id` on classes or offerings; dedicated `class_teacher_assignments`
- `subject_teacher_assignments` instead of `class_subject_teacher`; unique is `(staff, subject_offering)` so an offering may have more than one teacher
- One enrollment per student per academic session (unique), not a partial unique on “active”
- One active class teacher per offering is service-enforced (portable across SQLite/MySQL)
- Parent portal login is email + password (frontend previously used admission number)
- `promotions` exists with no API
- No profile-photo upload endpoints

### Known limitations

- Register a pupil / Appoint a master buttons are not create wizards
- Fees column on the pupil roll remains a placeholder (Phase 6)
- Staff directory subject/form columns are placeholders until a later teaching-load UI
- Parent “Add Child” is not wired
- Staff/parent HTML files under `/site/` are still readable without login; APIs are not
- Photo storage column exists without an upload UI
- No promotion wizard

### Remaining work

Phase 5 attendance is complete. See the Phase 5 entry below.

---

## Phase 5 — Attendance and daily academic operations (25 August 2026)

### What was implemented

Daily attendance hanging off **enrollment**, not `student_id + date`. One mark per enrollment per school date. Bulk class register is atomic. Corrections write `attendance_corrections`. Existing `AttendanceStatus` (`present`, `absent`, `late`) is unchanged. No `excused` status; parent “excused absence” remains a remark.

No assessments, exams, scores, results, report cards, fees, invoices, payments, admissions, timetable, assignments, learning materials, announcements, messaging, reports, or promotion wizard.

### Tables created

- `attendance_records`
- `attendance_corrections`

### Migrations

- `2026_08_25_200000_create_attendance_records_table`
- `2026_08_25_200100_create_attendance_corrections_table`

### Models created / modified

- `AttendanceRecord`, `AttendanceCorrection`
- `Enrollment::attendanceRecords()`
- `ClassSectionOffering::attendanceRecords()`
- `User::markedAttendance()`

### Services

- `AttendanceService` — mark, update, bulk, register, student/class summary, delete, date rules
- `PeopleAccessService` — `classTeacherOfferingIds()`, `canMarkAttendanceForOffering()`, `canViewAttendanceForOffering()`

### Controllers / requests / resources / policies

- `AttendanceController`
- `StoreAttendanceRequest`, `UpdateAttendanceRequest`, `BulkAttendanceRequest`
- `AttendanceRecordResource`
- `AttendanceRecordPolicy`

### Routes (`/api/v1`)

- `GET attendance/offerings`
- `GET attendance/register`
- `GET attendance/summary`
- `GET attendance`
- `GET/PUT/DELETE attendance/{attendance_record}`
- `POST attendance`
- `POST attendance/bulk`

### Seeders

`AttendanceSeeder` (idempotent, does not overwrite): Chiamaka Okafor `SRS/2025/0142` on 2025-09-10/11/12/15; Daniel Okoro `SRS/2025/0198` on 2025-09-10. Marker is Mrs. Eze when present.

### Frontend integrations (existing pages only)

- Staff Attendance (`public/site/staff/attendance.html` + `LWA_S/staff/attendance.html`) — live register, bulk save
- Parent/Student Attendance (`public/site/parent_student/attendance.html` + `LWA_S/parent_student/attendance.html`) — read-only history/summary
- `public/site/JS/portal-attendance.js` (copied to `LWA_S/JS/`)

No admin attendance HTML was built. No visual redesign.

### Tests created

- `tests/Feature/Attendance/AttendanceApiTest.php`
- `tests/Feature/Attendance/AttendanceBulkTest.php`
- `tests/Feature/Attendance/AttendanceAuthorizationTest.php`

### Commands executed

- `php artisan test --compact`
- `php artisan migrate --force`
- `php artisan db:seed --class=AttendanceSeeder --force`
- `php artisan migrate:status`

### Test results

`php artisan test --compact` — **128 passed** (736 assertions).

### Authorization / privacy

- Super admin / school admin: view, mark, correct, delete (delete refused when correction history exists). Admin corrections still write audit rows.
- Class teacher: mark/update assigned offering only.
- Subject teacher: view assigned offering register; cannot mark.
- Staff profile alone does not grant write access.
- Parent: view linked children only (IDOR 403). Cannot mark.
- Student: view own attendance only (IDOR 403). Cannot mark.
- Guests: 401.
- `marked_by` cannot be mass-assigned from the request.
- Resources do not expose passwords, tokens, or medical fields.

### Architectural decisions

- Daily attendance (frontend is a date + class register; timetable is deferred).
- Unique `(enrollment_id, marked_on)`.
- `class_section_offering_id` denormalized onto the mark for class-date queries.
- Percentage = `(present + late) / total * 100` to 1 decimal. Late counts as attended. Class `total` is pupils on roll that date.
- Dates: not future (`Africa/Lagos`); inside session `starts_on`/`ends_on`; on/after `enrolled_on`; on/before `left_on` when set. No weekend blocking. Terms often lack dates in the seeder; term filter uses term dates when present, otherwise the session.
- Bulk is transactional; an invalid pupil rolls the whole batch back. Existing marks upsert with `correction_reason` when status changes.
- Enrollment hard-delete is restricted while attendance exists; Phase 4 destroy still withdraws.

### Deviations from DATABASE_ARCHITECTURE.md

- `marked_by` references `users`, not `staff_profiles`.
- Added `attendance_corrections` (the original note said “corrections overwrite with audit” without a table).
- No `excused` value on `AttendanceStatus`.

### Known limitations

- No admin attendance UI
- No period/subject attendance
- No time-in column (parent table shows "—")
- Attendance History button on the staff page is not a separate screen
- HTML under `/site/` remains readable without login; APIs are not
- Seeded session `2025/2026` ends `2026-07-24`; staff UI defaults to `2025-09-10` so marks stay inside the session
- No charts or reporting engine

### Remaining work

Phase 6 fees is complete. See the Phase 6 entry below.

---

## Phase 6 — Fees and payments (25 August 2026)

### What was implemented

Fee catalogue, term invoices keyed by pupil + term (with enrollment for class/session context), payments posted by admission number, line-level allocations, void-not-delete. Amounts stored as kobo. No gateway.

No assessments, exams, results, report cards, admissions, timetable, assignments, materials, messaging, announcements, or reports.

### Tables created

- `fee_types`
- `fee_structures`
- `invoices`
- `invoice_items`
- `payments`
- `payment_allocations`

### Migrations

- `2026_08_25_210000_create_fee_types_table`
- `2026_08_25_210100_create_fee_structures_table`
- `2026_08_25_210200_create_invoices_table`
- `2026_08_25_210300_create_invoice_items_table`
- `2026_08_25_210400_create_payments_table`
- `2026_08_25_210500_create_payment_allocations_table`

### Models created / modified

- `FeeType`, `FeeStructure`, `Invoice`, `InvoiceItem`, `Payment`, `PaymentAllocation`
- `StudentProfile::invoices()` / `payments()`
- `Enrollment::invoices()`
- `User::recordedPayments()`
- `AcademicSession` / `Term` invoice relations

### Services

- `FeeCatalogueService`
- `InvoiceService` (create, generate for term, refresh totals from posted allocations, void invoice if no posted payments)
- `PaymentService` (post, allocate, void)
- `SchoolNumberService::nextInvoiceNumber()` / `nextPaymentReference()`
- `App\Support\Money` (naira ↔ kobo)

### Controllers / requests / resources / policies

- `FeeTypeController`, `FeeStructureController`, `InvoiceController`, `PaymentController`
- `app/Http/Requests/Fees/*`
- `app/Http/Resources/Fees/*`
- Policies for fee types, structures, invoices, payments

### Routes (`/api/v1`)

- `GET/POST /fee-types`, `GET/PUT/DELETE /fee-types/{fee_type}` (DELETE deactivates)
- `GET/POST /fee-structures`, `GET/PUT/DELETE /fee-structures/{fee_structure}`
- `GET /invoices`, `GET /invoices/summary`, `POST /invoices`, `POST /invoices/generate`
- `GET /invoices/{invoice}`, `GET /invoices/{invoice}/statement`, `DELETE /invoices/{invoice}` (voids)
- `GET/POST /payments`, `GET /payments/{payment}`, `POST /payments/{payment}/void`

Writes: `super_admin` / `school_admin`. Parent/student: read own invoices/payments only.

### Seeders

`FeesSeeder` (idempotent): four fee types; First Term 2025/2026 structures (Tuition ₦180,000, ICT ₦25,000, Development ₦50,000, Materials ₦30,000); invoices for enrolled pupils; Chiamaka two ₦100,000 transfers; Daniel ₦95,000 cash.

### Frontend integrations

- Admin Fees (`resources/frontend/admin/fees.html` + `LWA_S/admin/fees.html`) — post payment, recent receipts, ledger metrics
- Parent/Student Fees (`public/site/parent_student/fees.html` + `LWA_S`) — breakdown, history, print, receipt
- `public/site/JS/portal-fees.js` (copied to `LWA_S/JS/`)
- “Pay Outstanding Balance” explains that office payment is required (no gateway)

### Tests created

- `tests/Feature/Fees/FeesCatalogueTest.php`
- `tests/Feature/Fees/InvoiceApiTest.php`
- `tests/Feature/Fees/PaymentApiTest.php`
- `tests/Feature/Fees/FeesAuthorizationTest.php`

### Commands executed

- `php artisan test --compact`
- `php artisan migrate --force`
- `php artisan db:seed --class=FeesSeeder --force`

### Test results

`php artisan test --compact` — **146 passed** (809 assertions).

### Authorization / privacy

- Admins manage types, structures, invoices, payments, voids
- Teachers have no fee access
- Parents see linked children only (IDOR 403)
- Students see own invoices/payments only
- Guests 401
- `recorded_by` cannot be mass-assigned
- Payments cannot be deleted (policy `delete` is false)

### Architectural decisions

- One invoice per student per term (`unique student_profile_id + term_id`)
- Invoice hangs off enrollment so class/session remain historically correct
- Structure matching prefers class, then level, then session/term
- Posted allocations are the source of paid totals; voiding a payment recalculates
- Overpayment rejected; no credit-balance rule in this phase
- Admin form amount is naira; stored as kobo
- Invoice numbers `INV/{year}/0001`; payment references `FEE-0001`

### Deviations from DATABASE_ARCHITECTURE.md

- None material. `recorded_by` is `users` as specified. Fee-structure uniqueness with NULL scopes is service-enforced (portable across SQLite/MySQL) rather than a partial unique index.

### Known limitations

- No Paystack/Flutterwave
- No PDF statement (browser print)
- No admin UI for creating fee types/structures (API + seeder only; admin page is record-payment)
- Accountant role is still unused
- Fees column on the pupil roll remains a placeholder
- HTML under `/site/` remains readable without login; APIs are not

### Remaining work

Do not start Phase 7 until requested. Assessments/results (deferred from Phase 5) are complete. Numbered Phase 7 is public admissions and contact.

---

## Assessments and results (deferred from Phase 5) (25 August 2026)

### What was implemented

Score entry, stored term totals, class position, and grade scales. Staff grades grid and parent/student academics page are wired to `/api/v1`. This is the leftover academic work from Phase 5, not numbered Phase 7.

### Inspection performed

- `DATABASE_ARCHITECTURE.md` §22–26
- Staff `grades.html` (class/subject/assessment/session, one score column, Final Result option)
- Parent `academics.html` (child selector, average, position, CA/Exam/Total)
- Existing people: Mrs. Eze (JSS 2 A class teacher + Mathematics), Chiamaka `SRS/2025/0142`

### Files created

- Migrations `2026_08_25_220000` through `220400` (types, scales, scores, term results, summaries)
- Models: `AssessmentType`, `GradeScale`, `AssessmentScore`, `TermResult`, `TermSummary`
- `AssessmentService`, `AssessmentPolicy`
- `AssessmentCatalogueController`, `GradeController`, `ResultController`
- Requests/resources under `app/Http/Requests/Assessments` and `app/Http/Resources/Assessments`
- `routes/api/v1/assessments.php`
- `AssessmentSeeder`
- `public/site/JS/portal-grades.js`
- Tests under `tests/Feature/Assessments/`

### Files modified

- `PeopleAccessService` — `canEnterScoresFor`, `canViewScoresForOffering`, `assignedSubjectIdsForOffering`
- Relationships on `Enrollment`, `Term`, `Subject`, `User`
- `AppServiceProvider` policy map
- `routes/api/v1.php`
- `DatabaseSeeder`
- `CreatesAcademicContext` helpers
- Staff `grades.html` and parent `academics.html` (Laravel `public/site` + `LWA_S`)
- `DATABASE_ARCHITECTURE.md`, `IMPLEMENTATION_ROADMAP.md`, this log

### Migrations created

- `assessment_types`
- `grade_scales`
- `assessment_scores`
- `term_results`
- `term_summaries`

### Routes (`/api/v1`)

- `GET /assessment-types`, `GET /grade-scales`
- `GET /grades/contexts`, `GET /grades/register`
- `POST /grades`, `POST /grades/bulk`
- `GET /results`, `GET /results/summary`

### Seeders

`AssessmentSeeder` (idempotent): types 15/15/70; A–F scales; Chiamaka JSS 2 A first-term scores including Mathematics 14+14+66 = 94 (A).

### Frontend integrations

- Staff Grades (`public/site/staff/grades.html` + `LWA_S/staff/grades.html`)
- Parent/Student Academics (`public/site/parent_student/academics.html` + `LWA_S`)
- `portal-grades.js` — register, bulk save, CSV export, parent results, print
- Final Result is read-only; Save is hidden
- Download Result uses `window.print`

### Tests created

- `tests/Feature/Assessments/AssessmentApiTest.php`
- `tests/Feature/Assessments/AssessmentBulkTest.php`
- `tests/Feature/Assessments/AssessmentAuthorizationTest.php`

### Commands executed

- `php artisan test --compact`
- `php artisan migrate --force`
- `php artisan db:seed --class=AssessmentSeeder --force`

### Test results

`php artisan test --compact` — **166 passed** (896 assertions).

### Authorization / privacy

- Admins enter scores for any class/subject
- Active class teacher: any offered subject on that class
- Active subject teacher: assigned subject only
- Unassigned staff 403
- Parents see linked children only (IDOR 403)
- Students see own results only
- Guests 401
- `entered_by` cannot be mass-assigned
- Archived session writes rejected

### Architectural decisions

- Scores hang off **enrollment**, not student, so class/session stay historically correct
- CA total = non-exam types; total = CA + exam; maxima 15+15+70
- Missing cells count as 0 when storing `term_results` and ranking the class
- Class position is competition rank (ties share a place; next is count+1)
- Staff page has Session, not Term: uses school `current_term_id` when it belongs to the selected session, otherwise that session’s First Term
- Recalc after save: that subject’s `term_results`, then offering `term_summaries`

### Deviations from DATABASE_ARCHITECTURE.md

- `entered_by` is `users`, not `staff_profiles` (same reason as attendance `marked_by` and fee `recorded_by`: admins have no staff profile)
- No generic audit platform and no per-cell corrections table

### Known limitations

- No report-card PDF designer
- No class-teacher comment field (remark card explains that)
- No admin grades HTML (API + seeder only)
- Average includes every subject offered on the class (unmarked = 0)
- HTML under `/site/` remains readable without login; APIs are not
- Browser click-testing was not available in this session

### Remaining work

Phase 7 admissions/contact is complete. Do not start Phase 8 until requested.

---

## Phase 7 — Public admissions and contact (25 August 2026)

### What was implemented

Public contact and admission forms write to the database instead of opening a mail client. Admission attachments are stored on the private disk. The admin Mail chute reads those records.

### Inspection performed

- `DATABASE_ARCHITECTURE.md` §29, §32, §35
- `public/site/admissions.html` and `contact.html` (mailto submit handlers)
- Admin `messages.html` three-lane chute (Urgent / Watch / Cleared)
- Existing enums: `ApplicationStatus`, `EnquiryStatus`, `DocumentType`

### Files created

- Migrations `2026_08_25_230000` through `230200`
- Models: `Document`, `ContactEnquiry`, `AdmissionApplication`
- `DocumentService`, `EnquiryService`, `ApplicationService`, `InboxService`
- Controllers: `ContactEnquiryController`, `AdmissionApplicationController`, `InboxController`
- Requests/resources under `app/Http/Requests/Admissions` and `app/Http/Resources/Admissions`
- Policies for enquiries, applications, documents
- `routes/api/v1/admissions.php`
- `AdmissionsSeeder`
- `public/site/JS/portal-public.js`, `portal-inbox.js`
- Tests under `tests/Feature/Admissions/`

### Files modified

- `SchoolNumberService::nextApplicationReference()`
- Relationships on `User`, `Level`, `AcademicSession`, `StudentProfile`
- `AppServiceProvider` policy map
- `routes/api/v1.php` (health now uses `web` so public forms can obtain an XSRF cookie)
- `DatabaseSeeder`
- Public `admissions.html` / `contact.html` (Laravel `public/site` + `LWA_S`)
- Admin `messages.html` (`resources/frontend/admin` + `LWA_S`)
- `DATABASE_ARCHITECTURE.md`, `IMPLEMENTATION_ROADMAP.md`, this log

### Migrations created

- `documents`
- `contact_enquiries`
- `admission_applications`

### Routes (`/api/v1`)

Public (`web` + throttle, no login):

- `POST /contact-enquiries`
- `POST /admission-applications`

Admin (`web` + `auth`):

- `GET /inbox`, `POST /inbox/open`, `POST /inbox/clear-urgent`
- `GET /contact-enquiries`, `GET/PUT /contact-enquiries/{id}`
- `GET /admission-applications`, `GET/PUT /admission-applications/{id}`
- `GET /documents/{document}/download`

### Seeders

`AdmissionsSeeder` (idempotent): urgent campus-visit enquiry; general PTA enquiry; JSS 1 application for Ifeanyi Eze (`ADM-0001` on a fresh database).

### Frontend integrations

- Public Admissions and Contact — POST to `/api/v1`, CSRF via `/api/v1/health`
- Admin Mail chute — live lanes and metrics; click opens (marks enquiry read / application under review); “Clear urgent lane” clears urgent enquiries
- Header/footer `mailto:` display links remain; forms no longer compose mail

### Tests created

- `tests/Feature/Admissions/PublicAdmissionsTest.php`
- `tests/Feature/Admissions/AdmissionsAuthorizationTest.php`

### Commands executed

- `php artisan test --compact`
- `php artisan migrate --force`
- `php artisan db:seed --class=AdmissionsSeeder --force`

### Test results

`php artisan test --compact` — **176 passed** (953 assertions).

### Authorization / privacy

- Guests may submit contact and admission forms
- Guests cannot read the inbox or download files (401)
- Teachers and parents 403 on inbox, lists, status updates, and downloads
- Admins triage status and download attachments
- Public submit cannot mass-assign `status`, `reference`, or `student_profile_id`
- Files are stored on the `local` disk (`storage/app/private`), never `public`

### Architectural decisions

- Admission/fees/visit contact subjects start as `urgent`; other subjects as `unread`
- Applications stay in the urgent lane until admitted, rejected, or withdrawn
- Opening an application moves `submitted` → `under_review`
- “Clear urgent lane” only clears enquiries with status `urgent`
- Setting status to `admitted` does not create a pupil (no enrolment wizard in this phase)
- Form field names stay camelCase on the HTML; the Form Request maps them

### Deviations from DATABASE_ARCHITECTURE.md

- Document `disk` is Laravel’s `local` disk, whose root is already `storage/app/private`
- No `audit_logs` table yet (Phase 9); status is stored on the row only

### Known limitations

- No auto-enrolment when an application is admitted
- No email notification to the office (records land in the chute)
- Display `mailto:` links in the header/footer still exist
- HTML under `/site/` remains readable without login; APIs for the chute are not
- Browser click-testing was not available in this session

### Remaining work

Do not start Phase 8 until requested. Numbered Phase 8 is communication and classroom extras.

---

## Phase 8 — Communication and classroom extras (25 August 2026)

### What was implemented

Announcements, internal messages, timetables, assignments, learning materials, and Laravel database notifications. Existing admin/staff/parent pages are wired to `/api/v1` without a redesign. Assignment submissions, dashboards, reports, and `audit_logs` are still out of scope.

### Inspection performed

- `DATABASE_ARCHITECTURE.md` §27–28, §30–31, §33–34
- Staff compose forms (`#announcementForm`, `#assignmentForm`, `#uploadForm`, `#composeForm`)
- Admin notice board and timetable grid
- Parent announcement/assignment/material/timetable/message pages

### Files created

- Migrations `2026_08_25_203903` (notifications) and `240000`–`240400`
- Models: `Announcement`, `TimetableSlot`, `Assignment`, `LearningMaterial`, `Conversation`, `ConversationParticipant`, `Message`
- `SchoolNotice` notification (database channel)
- Services: `AnnouncementService`, `TimetableService`, `AssignmentService`, `MaterialService`, `MessagingService`, `ClassroomService`
- `ClassroomController`, requests/resources under `Classroom`, policies for the new models
- `routes/api/v1/classroom.php`
- `ClassroomSeeder`
- `public/site/JS/portal-classroom.js`
- Tests under `tests/Feature/Classroom/`

### Files modified

- `PeopleAccessService` (classroom offering scope, `canMessage`, posting rights)
- `DocumentService::storeFile`, `DocumentPolicy` (learning-material downloads)
- `AppServiceProvider` policy map
- `DatabaseSeeder`
- Admin `announcements.html` / `timetable.html`
- Staff and parent classroom pages (Laravel `public/site` + `LWA_S`)
- `DATABASE_ARCHITECTURE.md`, `IMPLEMENTATION_ROADMAP.md`, this log

### Migrations created

- `notifications`
- `announcements`
- `timetable_slots`
- `assignments`
- `learning_materials`
- `conversations`, `conversation_participants`, `messages`

### Routes (`/api/v1`, `web` + `auth`)

- `GET /classroom/context`
- `GET/POST /announcements`, `GET/PUT/DELETE /announcements/{id}`
- `GET /timetable`, `POST /timetable`, `PUT/DELETE /timetable/{timetable_slot}`
- `GET/POST /assignments`, `GET/PUT/DELETE /assignments/{assignment}`
- `GET/POST /learning-materials`, `DELETE /learning-materials/{learning_material}`
- `GET /messages/recipients`, `GET/POST /conversations`, `GET /conversations/{id}`, `POST /conversations/{id}/messages`
- `GET /notifications`, `POST /notifications/read-all`, `POST /notifications/{id}/read`
- Existing `GET /documents/{document}/download` now also serves class materials

### Seeders

`ClassroomSeeder` (idempotent): whole-school mid-term notice; PTA briefing for parents; JSS 2 A first-term grid (Mrs. Eze / Mr. Okoro / breaks); Mathematics Exercise 4; algebra week-3 PDF; Mrs. Eze ↔ Mrs. Okafor thread.

### Frontend integrations

- Admin Notices — compose + live board
- Admin Times — class filter + grid
- Staff announcements, assignments, materials, timetable, messages
- Parent announcements, assignments, materials, timetable, messages
- Bell/`notification-badge` unread count on pages that load `portal-classroom.js`
- Admin Mail chute is unchanged (Phase 7 admissions inbox)

### Tests created

- `tests/Feature/Classroom/AnnouncementApiTest.php`
- `tests/Feature/Classroom/TimetableApiTest.php`
- `tests/Feature/Classroom/ClassroomWorkApiTest.php`
- `tests/Feature/Classroom/MessagingApiTest.php`
- `tests/Feature/Classroom/PortalClassroomPagesTest.php`

### Commands executed

- `php artisan test --compact`
- `php artisan migrate --force`
- `php artisan db:seed --class=ClassroomSeeder --force`

### Test results

`php artisan test --compact` — **199 passed** (1049 assertions).

### Authorization / privacy

- Guests 401 on all classroom endpoints
- Published announcements are audience-scoped; drafts are author + admin
- Teachers cannot write the timetable
- Parents see only their children's class work and cannot read another house
- Assigned teachers set homework and materials; unrelated teachers 403
- Learning-material downloads are class-scoped; admission documents stay admin-only
- Messaging is blocked unless `PeopleAccessService::canMessage` allows the pair
- Outsiders 403 on another thread

### Architectural decisions

- Money and fees unchanged; no payment gateway
- No assignment submissions
- Pin buttons are not persisted (no pin column)
- Database notifications only (mailer is `log`)
- Admin compose maps UI labels (Whole school / Parents / Staff / Secondary only) onto the audience enum
- `day_of_week` is 1–5 (Monday–Friday)

### Deviations from DATABASE_ARCHITECTURE.md

- Laravel `notifications` landed with Phase 8 instead of waiting for Phase 9
- Unique participant pair is enforced in the service (reuse the existing two-person thread) rather than a database unique on the unordered pair

### Known limitations

- Staff/parent HTML under `/site/` remains readable without login; APIs are not
- Browser click-testing was not available in this session
- Staff pin buttons remain local to the page
- Parent “New message” compose modal is not a separate form; replies use the thread box; staff compose modal is wired

### Remaining work

Do not start Phase 9 until requested. Numbered Phase 9 is dashboards, reports, and `audit_logs`.

---

## Fees & payment management — ledger completion (27 August 2026)

### What was implemented

The Phase 6 fee tables already existed (`fee_types` → `fee_structures` → `invoices` / `invoice_items` → `payments` / `payment_allocations`). This pass made the financial position of every pupil visible and authoritative: expected, paid, balance, and derived status, with admin filters, household read models, and IDOR hardening.

No assessments, exams, results, report cards, admissions, timetable, assignments, materials, messaging, announcements, reports, or promotion wizard were added.

### Fee architecture

Student → Enrollment → Academic session / Term → Invoice (+ items) → Payments → derived balance/status.

Historical years stay as separate invoice rows. Totals are integer kobo. `paid_kobo` on invoices/items is a cache recalculated only from `posted` allocations.

### Invoice architecture

Existing `invoices` (unique student + term) with `invoice_items` describing tuition, ICT, development, materials, and later types. Status stored as `unpaid` / `partial` / `paid` / `void`. API adds `fee_status` (`outstanding` / `partially_paid` / `paid_in_full`) and labels. Status cannot be posted by an administrator independently of money.

### Payment architecture

Existing `payments` with unique `reference`. Auto references are `SRS-FEE-{year}-{6 digits}`. Office may supply a unique reference. `posted` allocates across invoice lines. `pending` and `failed` are stored but do not allocate or reduce balance. `void` is the auditable reversal (reason, `voided_by`, `voided_at`). Payments are never deleted.

### Balance / status calculation

`balance = max(0, total_kobo − posted paid_kobo)`  
`paid_in_full` when paid ≥ due; `partially_paid` when paid > 0 and due remains; `outstanding` when paid = 0. Overpayment is rejected.

### Authorization / IDOR

- Writes: `super_admin` / `school_admin`
- Teachers, including class teachers, cannot read invoices or payments
- Parents: linked children only (`GuardianStudent`)
- Students: own invoices/payments only
- Guests 401
- `recorded_by` cannot be mass-assigned
- Fee fields on the pupil roll are admin-only

### Database tables

No new tables. Existing Phase 6 schema retained. Restrict-on-delete unchanged.

### Models / services / HTTP

- `PaymentStatus` cases: `posted`, `pending`, `failed`, `void`
- `InvoiceStatus::feeStatus()` / `fromFilter()`
- `InvoiceService::applyLedgerFilters()`
- `PaymentService::post()` unique reference + non-posted statuses
- `SchoolNumberService::nextPaymentReference()` → `SRS-FEE-YYYY-000001`
- `InvoiceController` filters, summary counts, `me/fees`, `students/{student}/fees`
- `PaymentController` date filters, `me/payments`
- Policies deny teachers on `view`
- Resources: `fee_status`, `fee_status_label`, enrollment id on payments

### Routes (`/api/v1`)

Existing invoice/payment/catalogue routes kept (no duplicate `/fees` collection). Added:

- `GET /me/fees`, `GET /me/fees/summary`, `GET /me/payments`
- `GET /students/{student_profile}/fees`, `GET /students/{student_profile}/fees/summary`

Ledger filters on `GET /invoices` and `GET /invoices/summary`: session, term, class, section/arm, status aliases, student, admission number, search `q`, due date range. Summary adds paid-in-full / partial / outstanding counts.

### Seeders

`FeesSeeder` remains idempotent. Development example:

- `SRS/2025/0142` — ₦285,000 expected, paid in full (three posted transfers)
- `SRS/2025/0198` — ₦285,000 expected, ₦95,000 paid, partial
- `SRS/2025/0221` — ₦285,000 expected, ₦0 paid, outstanding

Catalogue remains Tuition ₦180,000 + ICT ₦25,000 + Development ₦50,000 + Materials ₦30,000.

### Frontend

- Admin `/portal/fees`: pupil ledger (fees, paid, balance, status + filters); payment date on post; live receipt reference
- Admin pupil roll: real fee state/label from current-session invoices (no placeholder)
- Parent/student `/parent/fees` and `/student/fees`: existing page wired to invoices/payments; students hide the child selector; parents cannot query another child

### Tests

Extended `InvoiceApiTest`, `PaymentApiTest`, `FeesAuthorizationTest`, `PortalFeesPageTest`, `ApprovedEnumsTest`.

### Commands executed

- `php artisan test --compact`

### Test results

`php artisan test --compact` — **313 passed** (2275 assertions).

### Architectural decisions

- Reuse Phase 6 tables rather than invent `fee_invoices`
- Keep stored invoice statuses; expose operational labels via `fee_status`
- Extend `PaymentStatus` instead of adding a second enum
- Enrollment on a payment is via `invoice.enrollment_id` (no extra column)

### Deviations from DATABASE_ARCHITECTURE.md

- `PaymentStatus` gained `pending` and `failed` (originally `posted`, `void`)
- Public payment references are `SRS-FEE-YYYY-000001` rather than `FEE-0001`
- No `GET /fees` alias (would duplicate `GET /invoices`)

### Known limitations

- No Paystack/Flutterwave; pending payments have no “mark posted later” action (post a new successful payment)
- No PDF receipt engine (stable reference + browser print)
- Overpayment / credit balance is not supported
- Accountant role remains unused
- HTML under `/site/` remains readable without login; APIs are not

### Remaining work

Password reset by email (admin/staff/parent) and pupil phone login are done. `audit_logs` is still open. Do not start another school module until requested.

---

## Sign-in restoration and pupil phone login (27 August 2026)

### What was implemented

Admin, staff, and parents restore a forgotten password from the **registered email** on their user record. A letter carries a one-hour link. The same generic reply is shown whether the address is on the books or not. Pupils do not use email reset.

Pupils sign into `/student/login` with **name or admission number**. The password is a **linked parent/guardian phone** (`phone` or `alternate_phone`), accepting common Nigerian formats (`0803…`, `+234 803…`).

### Routes

- `GET /portal/forgot-password`, `/staff/forgot-password`, `/parent/forgot-password`
- `POST /forgot-password` (`email`, `portal`)
- `GET /{portal}/reset-password/{token}`
- `POST /reset-password` (`token`, `email`, `password`, `password_confirmation`, `portal`)

Student login is still `POST /login` with `portal=student`. The stored `users.password` is no longer accepted at the pupil desk.

### Known limitations

- Mail uses the configured mailer (`log` locally; SMTP in `.env.example`)
- Pupil login requires a guardian phone on the books
- No student email reset (synthetic student addresses cannot receive mail)

---

---

## Portal reports — live school papers (27 August 2026)

### What was implemented

`/portal/reports` keeps the live assay (metrics, wings, fee ledger, admissions chute, week bars) and can now **draw, export CSV, and print** enrolment, fee, attendance, and staff papers from the same ledger. JSON is `Cache-Control: no-store`. The office page polls every 8 seconds and re-fetches the last drawn table so the paper stays current while the tab is open.

No assessments, exams, results, report cards, payment gateway, or `audit_logs` were added. Staff `/staff/reports` is unchanged.

### Endpoints

- `GET /api/v1/portal-reports` — live assay snapshot (school_admin / super_admin)
- `GET /api/v1/portal-reports/catalogue` — sessions, forms, kinds
- `GET /api/v1/portal-reports/generate?kind=` `roll` | `fees` | `attendance` | `staff`
- `GET /api/v1/portal-reports/export` — same filters, CSV with BOM

Optional filters: `academic_session_id`, `term_id` (fees), `class_section_offering_id`, `status` (fee aliases), `from`, `to`.

### Known limitations

- Papers are tables/CSV/print, not PDF
- Staff presence is “who posted the roll”, not a separate staff attendance clock
- HTML under `/site/` remains readable without login; APIs are not

---






