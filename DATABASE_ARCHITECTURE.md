# Supreme Reagan Schools — Database Architecture

**Status:** Phase 0–6 fees ledger complete. Local engine is **MySQL** (`supreme_reagan`). PHPUnit still uses in-memory SQLite.  
**Date:** 25 August 2026  
**Engine target:** MySQL/MariaDB `utf8mb4` (phpMyAdmin / XAMPP). Types below use Laravel/MySQL-friendly names.

This schema is derived from the existing `LWA_S` frontend. Tables that only exist to support a future UI are marked **deferred**.

### Frontend vs schema (Phase 4)

| Topic | Frontend | Architecture / implementation |
|-------|----------|-------------------------------|
| Staff | Teachers page, staff IDs `SRS/TCH/0012` | `staff_profiles` (one per user). Role distinguishes teacher vs other staff |
| Pupils | Admission `SRS/2025/0142`, form, guardian | `student_profiles` + `enrollments`. Current class is **not** on the profile |
| Parents | “My Children”, previously admission-number login | `guardian_profiles` + `guardian_student`. Parent login is **email + password** |
| Students | Admission-number login | Resolves `admission_number` → `student_profiles.user_id` → hashed `users.password` |
| Class teacher | Classes column “Class teacher” | `class_teacher_assignments` per offering/session, not `class_teacher_id` on the class |
| Subject teacher | Staff directory subjects | `subject_teacher_assignments` → `subject_offerings` |
| Photos | Avatar initials in UI | Nullable `photo_path` only. **No upload API** (frontend has no photo picker) |

### Frontend vs schema (Phase 5)

| Topic | Frontend | Architecture / implementation |
|-------|----------|-------------------------------|
| Granularity | Staff: “Record and manage **daily** student attendance”; date + class; Present / Absent / Late | **Daily** marks. No period/subject attendance (timetable is deferred) |
| Identity | Mock rows used student names | `attendance_records.enrollment_id` — class/session stay historically correct |
| Status | Present, Absent, Late. Parent mock “Excused absence” is a remark | Existing `AttendanceStatus`: `present`, `absent`, `late`. No `excused` status |
| Recorder | Teacher save button | `marked_by` → `users.id` (admins have no staff profile) |
| Corrections | Teacher can change a mark | `attendance_corrections` (from/to status, reason, who, when) |
| Parent UI | Child selector, rate, days present/absent/late, table | Read-only via `/attendance` and `/attendance/summary` |
| Admin UI | None | API only this phase. No new admin attendance page |

### Frontend vs schema (Phase 6)

| Topic | Frontend | Architecture / implementation |
|-------|----------|-------------------------------|
| Admin post | Admission number, amount ₦, Transfer/Cash/POS, note | `POST /payments` stores **kobo**; `recorded_by` is the authenticated user |
| Receipts | `FEE-881` | `payments.reference` like `SRS-FEE-2026-000001` (unique; never the database id) |
| Parent breakdown | Tuition, ICT, Development, Learning Materials | `fee_types` + `invoice_items`; one invoice per pupil per term |
| Pay Outstanding | Button with no processor | No gateway. UI tells the parent to pay at the office |
| Print statement | Button | JSON statement + `window.print`; no PDF engine |
| Overpayment | Not in UI | Rejected. Amount cannot exceed remaining invoice balance |
| Pupil ledger | Paid / Partial / Outstanding | Derived from invoice `total_kobo` − posted payments. Status is not a writable boolean |
| Payment outcomes | Posted receipts | `PaymentStatus`: `posted` counts; `pending`/`failed` do not; `void` reverses with an audit trail |

### Frontend vs schema (assessments / results)

| Topic | Frontend | Architecture / implementation |
|-------|----------|-------------------------------|
| Staff grid | Class, subject, assessment, session; one score column | `assessment_scores` keyed by enrollment + term + subject + type |
| Final Result | Fourth dropdown option | Read-only `term_results` view. Not a fourth assessment kind |
| CA / Exam maxima | Inputs were 0–100 in the mock | First CA 15, Second CA 15, Examination 70 |
| Grade bands | Filter labels A Excellent … F Needs Support | `grade_scales`: A 75–100, B 65–74, C 50–64, D 40–49, F 0–39 |
| Recorder | Save button | `entered_by` → `users.id` (admins have no staff profile) |
| Parent UI | Child selector, average, position, highest, CA/Exam/Total | `/results` and `/results/summary`; print, no PDF designer |
| Teacher remark card | Long class-teacher comment | No comment column. Per-subject remark comes from the grade scale |
| Export | Button | CSV of the current grid / `window.print` |

### Frontend vs schema (Phase 7)

| Topic | Frontend | Architecture / implementation |
|-------|----------|-------------------------------|
| Admissions form | mailto compose | `POST /admission-applications`; reference `ADM-0001` |
| Attachments | File inputs, previously “email or bring them” | `documents` on private `local` disk; admin download only |
| Contact form | mailto compose | `POST /contact-enquiries`; admission/fees/visit → `urgent` |
| Admin Mail | Mock three-lane chute | `GET /inbox` mixes applications (urgent) and enquiries |
| Display mailto | Header/footer email links | Left as contact links; forms no longer open the mail client |

---

### Frontend vs schema (Phase 3)

| Topic | Frontend | Architecture / implementation |
|-------|----------|-------------------------------|
| Levels | Setup mock says “3 levels”; class filters list four | **Four levels:** Nursery, Primary, Junior Secondary, Senior Secondary |
| Class names | Year-group (`JSS 2`, `Primary 4`) plus display form (`JSS 2A`) | Class catalogue without arm; arm stored on `class_sections` |
| Arms vs sections | UI shows `JSS 2A`; no dedicated “Arm” label | DB: `class_sections.arm`. API copy uses **Arm** |
| Sessions | `2025/2026`, First/Second/Third Term, 2 or 3 terms | `academic_sessions.term_count` in `{2,3}` |
| Settings | Name, address, phone, email | Those plus motto, website, timezone, current session/term. Clearance key is **not** stored |
| Subjects | Staff mocks: English Language, Mathematics, Basic Science | Reusable `subjects` plus `subject_offerings` per class-session |

---

## Design principles

1. `users` holds authentication only (name, email, password, status). Role-specific fields live in profile tables.
2. Academic history is never overwritten: enrollments, scores, invoices, and payments are rows in time, not mutable “current class” columns on the student.
3. Foreign keys everywhere. Restrict deletes on academic and financial records; do not cascade-delete results or payments.
4. Soft deletes only where a record may be hidden but must remain (`users`, people profiles, announcements). Do **not** soft-delete `payments` or `assessment_scores` as a substitute for reversal — use explicit void/reversal rows.
5. Money stored as integer **kobo** (₦1 = 100) to avoid float errors. API can present naira.
6. One operational campus now (`Owerri`). `campuses` still exists so the school is not hard-coded.
7. Audit: `created_by` / `updated_by` on sensitive tables plus a central `audit_logs` table.

---

## Entity relationship (logical)

```
roles ←→ users → staff_profiles
                → student_profiles → enrollments → class_section_offerings → class_sections
                → guardian_profiles ←→ guardian_student ←→ student_profiles

academic_sessions → terms
class_section_offerings → subject_offerings → subjects
staff_profiles → class_teacher_assignments → class_section_offerings
staff_profiles → subject_teacher_assignments → subject_offerings
enrollments + dates → attendance_records → attendance_corrections
enrollments + terms + subjects → assessment_scores → term_results
fee_structures → invoices → invoice_items
invoices → payments → payment_allocations
admission_applications → documents
users → conversations → messages
```

---

## Laravel default tables (keep)

Do not recreate. Already migrated:

- `users` — **will be extended**, not replaced
- `password_reset_tokens`
- `sessions`
- `cache`, `cache_locks`
- `jobs`, `job_batches`, `failed_jobs`

---

## Enums (PHP-backed; stored as strings)

| Enum | Values |
|------|--------|
| `UserStatus` | `active`, `inactive`, `suspended` |
| `RoleSlug` | `super_admin`, `school_admin`, `principal`, `vice_principal`, `teacher`, `accountant`, `staff`, `parent`, `student` |
| `Gender` | `male`, `female` |
| `GuardianRelationship` | `father`, `mother`, `guardian` |
| `StudentStatus` | `pending`, `active`, `inactive`, `graduated`, `withdrawn` |
| `StaffStatus` | `active`, `on_leave`, `inactive` |
| `EnrollmentStatus` | `active`, `completed`, `transferred`, `withdrawn` |
| `SessionStatus` | `planned`, `active`, `archived` |
| `AttendanceStatus` | `present`, `absent`, `late` |
| `AssessmentKind` | `first_ca`, `second_ca`, `examination` |
| `AnnouncementAudience` | `whole_school`, `parents`, `staff`, `students`, `secondary`, `teaching_staff`, `non_teaching_staff`, `department` |
| `AnnouncementCategory` | `academic`, `event`, `general`, `urgent` |
| `AnnouncementStatus` | `draft`, `published`, `archived` |
| `FeeChannel` | `cash`, `transfer`, `pos`, `other` |
| `InvoiceStatus` | `unpaid`, `partial`, `paid`, `void` |
| `PaymentStatus` | `posted`, `pending`, `failed`, `void` |
| `ApplicationStatus` | `submitted`, `under_review`, `exam_scheduled`, `offered`, `admitted`, `rejected`, `withdrawn` |
| `EnquiryStatus` | `unread`, `read`, `urgent`, `cleared` |
| `DocumentType` | `passport_photo`, `birth_certificate`, `exam_receipt`, `learning_material`, `other` |

---

## 1. `users` (extend existing)

**Purpose:** Login identity for every person who can authenticate.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint PK | no | existing |
| `name` | string | no | display name |
| `email` | string unique | **yes after change** | students may log in with admission number only |
| `email_verified_at` | timestamp | yes | existing |
| `password` | string | no | hashed |
| `phone` | string(30) | yes | |
| `status` | string(20) | no | `UserStatus`, default `active` |
| `must_change_password` | boolean | no | default false |
| `last_login_at` | timestamp | yes | |
| `remember_token` | string | yes | existing |
| `created_at` / `updated_at` | timestamps | no | |
| `deleted_at` | timestamp | yes | soft delete |

**Indexes:** unique `email` (multiple NULLs allowed); index `status`.  
**Relationships:** belongsToMany `roles`; hasOne profile by role.  
**Audit:** role changes, status, password reset.

---

## 2. `roles`

**Purpose:** Named roles. Seeded, not user-editable slugs.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `name` | string | no |
| `slug` | string unique | no |
| `description` | string | yes |
| `created_at` / `updated_at` | timestamps | no |

No soft deletes.

---

## 3. `role_user`

**Purpose:** A user may hold one role at launch; pivot allows future dual roles.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `user_id` | FK users | no |
| `role_id` | FK roles | no |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(user_id, role_id)`  
**Indexes:** `user_id`, `role_id`  
**FK:** restrict on delete

---

## 4. `school_settings`

**Purpose:** Singleton school identity (admin Setup page). One row (`id = 1`).

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint PK | no | |
| `name` | string | no | Supreme Reagan Schools |
| `short_name` | string | yes | SRS |
| `motto` | string | yes | |
| `address` | string | yes | |
| `city` | string | yes | |
| `state` | string | yes | |
| `phone` | string | yes | |
| `email` | string | yes | |
| `admissions_email` | string | yes | |
| `whatsapp` | string | yes | |
| `website` | string | yes | |
| `timezone` | string | no | default `Africa/Lagos` |
| `founded_on` | date | yes | 2010-09-13 |
| `office_opens_at` | time | yes | |
| `office_closes_at` | time | yes | |
| `current_academic_session_id` | FK sessions | yes | restrict |
| `current_term_id` | FK terms | yes | restrict; must belong to current session |
| `logo_path` | string | yes | |
| `updated_by` | FK users | yes | null on user delete |
| `created_at` / `updated_at` | timestamps | no | |

**Not stored:** `super_admin_clearance_key`. Phase 2 treated the sovereign clearance field as visual-only.

No soft delete. **Audit:** `updated_by` only in Phase 3 (central `audit_logs` remains later).

---

## 5. `campuses`

**Purpose:** Physical site. Seed Owerri. Avoid hard-coding.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `name` | string unique | no |
| `address` | string | yes |
| `is_active` | boolean | no |
| `created_at` / `updated_at` | timestamps | no |

---

## 6. `academic_sessions`

**Purpose:** School year (admin Session page).

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint PK | no | |
| `name` | string unique | no | `2025/2026` |
| `starts_on` | date | no | |
| `ends_on` | date | no | |
| `term_count` | unsignedTinyInteger | no | 2 or 3 |
| `status` | string(20) | no | `SessionStatus` |
| `created_by` | FK users | yes | |
| `created_at` / `updated_at` | timestamps | no | |

**Indexes:** `status`, `starts_on`  
**Constraint:** only one `status = active` (enforced in service)  
**Soft delete:** no (archive via status)  
**Audit:** yes

---

## 7. `terms`

**Purpose:** First / second / third term inside a session.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `academic_session_id` | FK sessions restrict | no |
| `name` | string | no |
| `term_number` | unsignedTinyInteger | no |
| `starts_on` | date | yes |
| `ends_on` | date | yes |
| `status` | string(20) | no | `SessionStatus` (`planned` / `active` / `archived`) |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(academic_session_id, term_number)`, `(academic_session_id, name)`  
**Indexes:** `academic_session_id`, `status`  
**FK:** session restrict on delete  
**Current term:** `school_settings.current_term_id` (no `is_current` column — avoids two sources of truth)

---

## 8. `levels`

**Purpose:** Nursery, Primary, Junior Secondary, Senior Secondary.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `name` | string unique | no |
| `slug` | string unique | no |
| `description` | string | yes |
| `sort_order` | unsignedInteger | no |
| `is_active` | boolean | no |
| `created_at` / `updated_at` | timestamps | no |

Seeded slugs: `nursery`, `primary`, `jss`, `ss`. No soft delete.

---

## 9. `school_classes`

**Purpose:** Year-group without arm: Nursery 2, Primary 4, JSS 2, SS 1.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `level_id` | FK levels | no |
| `name` | string | no |
| `short_code` | string | yes |
| `sort_order` | unsignedInteger | no |
| `is_active` | boolean | no |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(level_id, name)`  
**Indexes:** `level_id`, `is_active`

---

## 10. `class_sections` (catalogue)

**Purpose:** The form/arm the UI calls “JSS 2A”.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint PK | no | |
| `school_class_id` | FK school_classes | no | |
| `arm` | string(5) | no | `A`, `B`, `C`, or empty |
| `name` | string | no | display `JSS 2A` |
| `is_active` | boolean | no | |
| `created_at` / `updated_at` | timestamps | no | |

**Unique:** `(school_class_id, arm)`  
**Index:** `name`

---

## 11. `class_section_offerings`

**Purpose:** This form in a given session (capacity, campus). Class teacher is **not** stored here; see `class_teacher_assignments`.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `class_section_id` | FK class_sections | no |
| `academic_session_id` | FK sessions | no |
| `campus_id` | FK campuses | no |
| `capacity` | unsignedInteger | no |
| `is_active` | boolean | no |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(class_section_id, academic_session_id)`  
**Indexes:** `academic_session_id`  
**FK:** restrict; do not delete if subject offerings (or later enrollments) exist

**Deferred:** none for Phase 4. `class_teacher_id` was intentionally never added; assignments are historical rows.

---

## 12. `departments`

**Purpose:** Staff filters (Sciences, Arts, Languages, Mathematics, ICT, Primary).

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `name` | string unique | no |
| `is_active` | boolean | no |
| `created_at` / `updated_at` | timestamps | no |

---

## 13. `subjects`

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `department_id` | FK departments | yes |
| `name` | string unique | no |
| `code` | string unique | yes |
| `description` | string | yes |
| `is_active` | boolean | no |
| `created_at` / `updated_at` | timestamps | no |

**Index:** `department_id`

---

## 14. `subject_offerings`

**Purpose (Phase 3):** Which reusable subject is offered on a class-section offering (session + arm). Teachers are not attached yet.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `class_section_offering_id` | FK offerings | no |
| `subject_id` | FK subjects | no |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(class_section_offering_id, subject_id)`  
**FK:** restrict

This is the Phase 3 stand-in for attaching subjects to a form-session. Teachers attach in Phase 4 via `subject_teacher_assignments`.

The original name `class_subject_teacher` was **not** used. Assignments reference `subject_offerings`, so more than one teacher can be attached to the same offering.

---

## 15. `staff_profiles`

**Purpose:** Teaching and non-teaching staff details. Not dumped onto `users`.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint PK | no | |
| `user_id` | FK users unique | no | |
| `staff_number` | string unique | no | `SRS/TCH/0012` |
| `department_id` | FK departments | yes | |
| `gender` | string(10) | yes | |
| `job_title` | string | yes | Class Teacher |
| `phone` | string | yes | Contact only |
| `status` | string(20) | no | `StaffStatus` |
| `employed_on` | date | yes | |
| `photo_path` | string | yes | Storage path only; no upload endpoint |
| `created_at` / `updated_at` | timestamps | no | |
| `deleted_at` | timestamp | yes | |

**Indexes:** `department_id`, `status`  
**Audit:** yes

---

## 16. `student_profiles`

**Purpose:** Pupil bio and identifiers. Current class is **not** stored here.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint PK | no | |
| `user_id` | FK users unique | yes | null until portal account exists |
| `admission_number` | string unique | no | `SRS/2025/0142` |
| `surname` | string | no | |
| `first_name` | string | no | |
| `other_names` | string | yes | |
| `gender` | string(10) | no | |
| `date_of_birth` | date | yes | |
| `nationality` | string | yes | |
| `state_of_origin` | string | yes | |
| `lga` | string | yes | |
| `home_address` | text | yes | |
| `phone` | string | yes | |
| `email` | string | yes | Contact email; login email lives on `users` |
| `blood_group` | string(5) | yes | sensitive |
| `genotype` | string(5) | yes | sensitive |
| `medical_notes` | text | yes | sensitive |
| `interests` | string | yes | |
| `previous_school` | string | yes | |
| `status` | string(20) | no | `StudentStatus` |
| `admitted_on` | date | yes | |
| `photo_path` | string | yes | Storage path only; no upload endpoint |
| `created_at` / `updated_at` | timestamps | no | |
| `deleted_at` | timestamp | yes | |

**Indexes:** `status`, `surname`, `first_name`  
**Audit:** yes. Health fields hidden from APIs except authorized roles.

---

## 17. `guardian_profiles`

**Purpose:** Parent/guardian person record. Phone/email kept here for guardians without a login yet.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `user_id` | FK users unique | yes |
| `full_name` | string | no |
| `phone` | string | yes |
| `alternate_phone` | string | yes |
| `email` | string | yes |
| `occupation` | string | yes |
| `address` | text | yes |
| `created_at` / `updated_at` | timestamps | no |
| `deleted_at` | timestamp | yes |

**Indexes:** `email`, `phone`

---

## 18. `guardian_student`

**Purpose:** Parent portal “My Children”.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `guardian_profile_id` | FK | no |
| `student_profile_id` | FK | no |
| `relationship` | string(20) | no |
| `is_primary` | boolean | no |
| `can_login` | boolean | no |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(guardian_profile_id, student_profile_id)`  
**Index:** `student_profile_id`

If parent logs in, they use **email + password** on `users`. Admission-number login is for students only.

---

## 19a. `class_teacher_assignments`

**Purpose:** Who is class teacher of a form in a given session. Historical.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint PK | no | |
| `staff_profile_id` | FK staff_profiles restrict | no | Must be assignable teaching staff |
| `class_section_offering_id` | FK restrict | no | Section + session |
| `is_active` | boolean | no | Service keeps one active teacher per offering |
| `assigned_on` | date | no | |
| `ended_on` | date | yes | Set when replaced or ended |
| `assigned_by` | FK users | yes | `nullOnDelete` |
| `created_at` / `updated_at` | timestamps | no | |

**Unique:** `(staff_profile_id, class_section_offering_id)` — same teacher can return in a later year via a new offering  
**Indexes:** `class_section_offering_id`, `is_active`  
**Delete:** ending an assignment sets `is_active=false`; rows are kept

---

## 19b. `subject_teacher_assignments`

**Purpose:** Who teaches a subject offering. Replaces the unused `class_subject_teacher` name.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint PK | no | |
| `staff_profile_id` | FK staff_profiles restrict | no | |
| `subject_offering_id` | FK subject_offerings restrict | no | |
| `is_active` | boolean | no | |
| `assigned_on` | date | no | |
| `ended_on` | date | yes | |
| `assigned_by` | FK users | yes | `nullOnDelete` |
| `created_at` / `updated_at` | timestamps | no | |

**Unique:** `(staff_profile_id, subject_offering_id)` — more than one teacher may share an offering  
**Indexes:** `subject_offering_id`, `is_active`

---

---

## 19. `enrollments`

**Purpose:** Student in a form for a session. Source of “current class”.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `student_profile_id` | FK restrict | no |
| `class_section_offering_id` | FK restrict | no |
| `academic_session_id` | FK | no |
| `status` | string(20) | no |
| `enrolled_on` | date | no |
| `left_on` | date | yes |
| `created_by` | FK users | yes |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(student_profile_id, academic_session_id)` — one form per year  
**Indexes:** `class_section_offering_id`, `status`  
**Soft delete:** no  
**Audit:** yes

---

## 20. `promotions`

Create with enrollment; dedicated UI later.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `student_profile_id` | FK | no |
| `from_enrollment_id` | FK enrollments | yes |
| `to_enrollment_id` | FK enrollments | yes |
| `academic_session_id` | FK | no |
| `decision` | string | no |
| `decided_by` | FK users | yes |
| `created_at` / `updated_at` | timestamps | no |

---

## 21. `attendance_records`

**Purpose:** One daily mark per enrollment. Staff attendance page. Implemented in Phase 5.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `enrollment_id` | FK enrollments, **restrictOnDelete** | no |
| `class_section_offering_id` | FK class_section_offerings, **restrictOnDelete** (copied from the enrollment at write time) | no |
| `marked_on` | date | no |
| `status` | string(20), `AttendanceStatus` | no |
| `remark` | string | yes |
| `marked_by` | FK **users**, `nullOnDelete` | yes |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(enrollment_id, marked_on)` as `attendance_enrollment_date_unique`  
**Indexes:** `(class_section_offering_id, marked_on)`, `marked_on`, `status`, `marked_by`  
**Soft delete:** no.

**Deviation:** architecture originally listed `marked_by` → `staff_profiles`. Implemented as `users.id` so school/super admins (who have no staff profile) can mark and correct.

Enrollment destroy in Phase 4 **withdraws** (sets `left_on` / status). Hard-deleting an enrollment that still has attendance is rejected by the FK.

---

## 21b. `attendance_corrections`

**Purpose:** Lightweight audit of attendance changes. Not a generic enterprise audit log.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `attendance_record_id` | FK attendance_records, **restrictOnDelete** | no |
| `from_status` | string(20) | no |
| `to_status` | string(20) | no |
| `from_remark` | string | yes |
| `to_remark` | string | yes |
| `reason` | string | no |
| `corrected_by` | FK users, `nullOnDelete` | yes |
| `created_at` | timestamp | no |

No `updated_at`. A status change requires `correction_reason`. Records with correction history cannot be deleted; correct them instead.

---

## 22. `assessment_types`

**Purpose:** First CA, Second CA, Examination. Max marks configurable (not hard-coded). Implemented with assessments/results (deferred from Phase 5).

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `kind` | string(20) unique | no |
| `name` | string | no |
| `max_score` | decimal(6,2) | no |
| `sort_order` | unsignedInteger | no |
| `is_active` | boolean | no |
| `created_at` / `updated_at` | timestamps | no |

---

## 23. `assessment_scores`

**Purpose:** One score cell on the staff grades grid. Implemented with assessments/results (deferred from Phase 5).

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `enrollment_id` | FK | no |
| `term_id` | FK terms | no |
| `subject_id` | FK subjects | no |
| `assessment_type_id` | FK | no |
| `score` | decimal(6,2) | no |
| `entered_by` | FK **users**, `nullOnDelete` | yes |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(enrollment_id, term_id, subject_id, assessment_type_id)`  
**Indexes:** `(term_id, subject_id)`  
**Constraint:** `score` between 0 and assessment max (Form Request + service)  
**Soft delete:** no. Archived sessions locked in the service.  
**Audit:** `entered_by` + timestamps. No per-cell corrections table (teachers edit grids often).

---

## 24. `term_results`

**Purpose:** Stored totals for parent academics. Implemented with assessments/results (deferred from Phase 5).

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `enrollment_id` | FK | no |
| `term_id` | FK | no |
| `subject_id` | FK | no |
| `ca_total` | decimal(6,2) | no |
| `exam_score` | decimal(6,2) | no |
| `total` | decimal(6,2) | no |
| `grade` | string(5) | yes |
| `remark` | string | yes |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(enrollment_id, term_id, subject_id)`

---

## 25. `term_summaries`

Overall average and class position for a term. Implemented with assessments/results (deferred from Phase 5).

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `enrollment_id` | FK | no |
| `term_id` | FK | no |
| `average` | decimal(6,2) | yes |
| `class_position` | unsignedInteger | yes |
| `class_size` | unsignedInteger | yes |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(enrollment_id, term_id)`

---

## 26. `grade_scales`

Configurable cut-offs (A–F). Seeded 15+15+70 Nigerian scale. Implemented with assessments/results (deferred from Phase 5).

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `min_score` | decimal(6,2) | no |
| `max_score` | decimal(6,2) | no |
| `grade` | string(5) | no |
| `remark` | string | no |
| `sort_order` | unsignedInteger | no |
| `created_at` / `updated_at` | timestamps | no |

---

## 27. `timetable_slots`

**Purpose:** Admin/staff/parent timetable grid. Implemented in Phase 8. `day_of_week` is ISO (1 = Monday … 5 = Friday). Breaks store a `label` with no subject.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `class_section_offering_id` | FK | no |
| `term_id` | FK | yes |
| `day_of_week` | unsignedTinyInteger | no |
| `starts_at` | time | no |
| `ends_at` | time | no |
| `subject_id` | FK subjects | yes |
| `staff_profile_id` | FK | yes |
| `label` | string | yes |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(class_section_offering_id, day_of_week, starts_at)`

---

## 28. `assignments`

Homework set by the class or subject teacher. Implemented in Phase 8. No `assignment_submissions` table.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `class_section_offering_id` | FK | no |
| `subject_id` | FK | no |
| `staff_profile_id` | FK | no |
| `title` | string | no |
| `instructions` | text | yes |
| `due_on` | date | no |
| `created_at` / `updated_at` | timestamps | no |
| `deleted_at` | timestamp | yes |

**Indexes:** `due_on`, `class_section_offering_id`

`assignment_submissions` is **deferred** until a submission UI exists.

---

## 29. `documents`

**Purpose:** All uploads (admissions, photos, materials). Implemented in Phase 7 for admission attachments. Private `local` disk (`storage/app/private`).

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `documentable_type` | string | no |
| `documentable_id` | bigint | no |
| `type` | string(30) | no |
| `disk` | string | no |
| `path` | string | no |
| `original_name` | string | no |
| `mime_type` | string | no |
| `size_bytes` | unsignedBigInteger | no |
| `uploaded_by` | FK users | yes |
| `created_at` / `updated_at` | timestamps | no |

**Indexes:** morph (`documentable_type`, `documentable_id`), `type`  
Private disk; authorize downloads. Admission attachments remain admin-only. Learning-material files may be downloaded by staff, parents, and pupils who can see that class.

---

## 30. `learning_materials`

Teacher uploads for a class + subject. Implemented in Phase 8. File is required (`document_id` is not nullable). Stored on the private `local` disk.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `class_section_offering_id` | FK | no |
| `subject_id` | FK | no |
| `staff_profile_id` | FK | no |
| `title` | string | no |
| `document_id` | FK documents | no |
| `created_at` / `updated_at` | timestamps | no |
| `deleted_at` | timestamp | yes |

---

## 31. `announcements`

School notices. Implemented in Phase 8. Drafts are visible to the author and admins only. There is no pin column; staff pin buttons are visual only.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `title` | string | no |
| `body` | text | no |
| `category` | string(20) | yes |
| `audience` | string(30) | no |
| `department_id` | FK | yes |
| `status` | string(20) | no |
| `published_at` | timestamp | yes |
| `created_by` | FK users | no |
| `created_at` / `updated_at` | timestamps | no |
| `deleted_at` | timestamp | yes |

**Indexes:** `status`, `published_at`, `audience`

---

## 32. `contact_enquiries`

Public contact form and admin inbound chute. Implemented in Phase 7.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `name` | string | no |
| `phone` | string | no |
| `email` | string | no |
| `subject` | string | no |
| `message` | text | no |
| `status` | string(20) | no |
| `assigned_to` | FK users | yes |
| `created_at` / `updated_at` | timestamps | no |

**Indexes:** `status`, `created_at`

---

## 33. Messaging

Internal staff/parent/student threads (not the admin admissions chute). Implemented in Phase 8. Replies are kept; there is no message-delete UI.

### `conversations`

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `subject` | string | no |
| `created_by` | FK users | no |
| `created_at` / `updated_at` | timestamps | no |

### `conversation_participants`

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `conversation_id` | FK | no |
| `user_id` | FK | no |
| `last_read_at` | timestamp | yes |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(conversation_id, user_id)`

### `messages`

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `conversation_id` | FK | no |
| `sender_id` | FK users | no |
| `body` | text | no |
| `created_at` / `updated_at` | timestamps | no |

**Index:** `(conversation_id, created_at)`

---

## 34. Laravel `notifications`

Implemented in Phase 8 (database channel only; the mailer remains `log`). Used for announcement publish, new assignments, and new messages.

- `id` uuid
- `type`
- `notifiable` morph
- `data` json (`title`, `body`, `kind`, `related_id`)
- `read_at`
- timestamps

---

## 35. `admission_applications`

Maps `admissions.html`. Implemented in Phase 7. Public submit stores the row; documents morph to this model. Admitting does **not** auto-create a pupil.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | bigint PK | no | |
| `reference` | string unique | no | `ADM-214` |
| `academic_session_id` | FK | yes | |
| `session_name` | string | no | raw form value |
| `level_id` | FK levels | yes | |
| `class_applied` | string | no | |
| `entry_term` | string | no | |
| `surname` | string | no | |
| `first_name` | string | no | |
| `other_names` | string | yes | |
| `gender` | string(10) | no | |
| `date_of_birth` | date | no | |
| `nationality` | string | no | |
| `state_of_origin` | string | no | |
| `lga` | string | yes | |
| `home_address` | text | no | |
| `previous_school` | string | yes | |
| `last_class` | string | yes | |
| `parent_name` | string | no | |
| `relationship` | string(20) | no | |
| `parent_phone` | string | no | |
| `parent_email` | string | no | |
| `parent_occupation` | string | yes | |
| `parent_second_phone` | string | yes | |
| `parent_address` | text | yes | |
| `blood_group` | string | yes | |
| `genotype` | string | yes | |
| `allergies` | text | yes | |
| `interests` | string | yes | |
| `status` | string(30) | no | |
| `student_profile_id` | FK | yes | set when admitted |
| `created_at` / `updated_at` | timestamps | no | |

**Indexes:** `status`, `parent_email`, `created_at`  
Documents via morph `documents`.  
**Audit:** status changes.

---

## 36. Fees and payments

**Implemented in Phase 6, ledger completed 27 August 2026.** Money is integer **kobo**. Payments are never deleted; they are voided. `recorded_by` / `voided_by` are `users`. Invoice `paid_kobo` and item `paid_kobo` are recalculated from posted (non-void) allocations. Only `posted` payments count toward paid totals. Overpayment is rejected. No payment gateway.

Public payment references are `SRS-FEE-{year}-{6 digits}` (or an office-supplied unique reference). Database ids are not used as public references.

Invoice `status` remains `unpaid` / `partial` / `paid` / `void`. The API also exposes `fee_status` / `fee_status_label`: `outstanding`, `partially_paid`, `paid_in_full`. Those labels are derived from due versus posted payments; they cannot be written directly.

### `fee_types`

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `name` | string unique | no |
| `code` | string unique | no |
| `is_active` | boolean | no |
| `created_at` / `updated_at` | timestamps | no |

Seed: Tuition, ICT Fee, Development Levy, Learning Materials.

### `fee_structures`

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `fee_type_id` | FK | no |
| `academic_session_id` | FK | no |
| `term_id` | FK | yes |
| `level_id` | FK | yes |
| `school_class_id` | FK | yes |
| `amount_kobo` | unsignedBigInteger | no |
| `created_at` / `updated_at` | timestamps | no |

**Index:** `(academic_session_id, term_id, fee_type_id)`

### `invoices`

One per student per term (parent “Current Term Fees”).

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `number` | string unique | no |
| `student_profile_id` | FK | no |
| `enrollment_id` | FK | yes |
| `academic_session_id` | FK | no |
| `term_id` | FK | no |
| `status` | string(20) | no |
| `total_kobo` | unsignedBigInteger | no |
| `paid_kobo` | unsignedBigInteger | no |
| `due_on` | date | yes |
| `created_at` / `updated_at` | timestamps | no |

**Indexes:** `student_profile_id`, `status`, `(academic_session_id, term_id)`  
**Unique:** `(student_profile_id, term_id)`  
**Soft delete:** no

### `invoice_items`

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `invoice_id` | FK restrict | no |
| `fee_type_id` | FK | no |
| `description` | string | no |
| `amount_kobo` | unsignedBigInteger | no |
| `paid_kobo` | unsignedBigInteger | no |
| `created_at` / `updated_at` | timestamps | no |

### `payments`

Never delete; void instead.

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `reference` | string unique | no |
| `student_profile_id` | FK | no |
| `invoice_id` | FK | yes |
| `amount_kobo` | unsignedBigInteger | no |
| `channel` | string(20) | no |
| `note` | string | yes |
| `paid_at` | timestamp | no |
| `status` | string(20) | no |
| `recorded_by` | FK users | no |
| `voided_by` | FK users | yes |
| `voided_at` | timestamp | yes |
| `void_reason` | string | yes |
| `created_at` / `updated_at` | timestamps | no |

**Indexes:** `student_profile_id`, `paid_at`, `status`  
**Unique:** `reference`  
**Audit:** yes (`voided_by`, `voided_at`, `void_reason`)  
**Transaction:** posting + allocation + invoice totals in one DB transaction.

### `payment_allocations`

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `payment_id` | FK | no |
| `invoice_item_id` | FK | no |
| `amount_kobo` | unsignedBigInteger | no |
| `created_at` / `updated_at` | timestamps | no |

**Unique:** `(payment_id, invoice_item_id)`

---

## 37. `audit_logs`

| Column | Type | Nullable |
|--------|------|----------|
| `id` | bigint PK | no |
| `user_id` | FK users | yes |
| `action` | string | no |
| `auditable_type` | string | no |
| `auditable_id` | bigint | no |
| `old_values` | json | yes |
| `new_values` | json | yes |
| `ip_address` | string(45) | yes |
| `user_agent` | string | yes |
| `created_at` | timestamp | no |

**Indexes:** morph, `user_id`, `created_at`  
No `updated_at`. No deletes.

---

## Deletion rules

| Table | On parent delete |
|-------|------------------|
| `assessment_scores`, `term_results`, `payments`, `invoices` | **Restrict** |
| `enrollments` | Restrict if scores/attendance exist (attendance FK is restrict; Phase 4 destroy **withdraws**) |
| `users` | Soft delete; profiles remain |
| `academic_sessions` | Restrict if terms or offerings exist; archive instead |
| `class_section_offerings` | Restrict if subject offerings (or later enrollments) exist |
| `levels` / `school_classes` / `class_sections` / `subjects` / `campuses` | Restrict while children exist |

---

## Tables not created in the first implementation

- Online payment provider tables (Paystack/Flutterwave)
- Report-card template builders
- Hostel / house registers
- Library / inventory
- Payroll
- Fine-grained permission bit tables (start with roles + policies)
- `assignment_submissions`

---

## Migration groups

1. Extend `users`; `roles`; `role_user` — **done (Phase 2)**
2. `school_settings`; `campuses`; `academic_sessions`; `terms`; `levels`; `school_classes`; `class_sections`; `class_section_offerings`; `departments`; `subjects`; `subject_offerings` — **done (Phase 3)**
3. `staff_profiles`; `class_teacher_assignments`; `subject_teacher_assignments` — **done (Phase 4)**
4. `student_profiles`; `guardian_profiles`; `guardian_student`; `enrollments`; `promotions` — **done (Phase 4)**
5. `attendance_records`; `attendance_corrections` — **done (Phase 5)**
6. `assessment_types`; `assessment_scores`; `term_results`; `term_summaries`; `grade_scales` — **done (assessments/results, deferred from Phase 5)**
7. `fee_types` through `payment_allocations` — **done (Phase 6)**
8. `admission_applications`; `documents`; `contact_enquiries` — **done (Phase 7)**
9. `announcements`; messaging; `timetable_slots`; `assignments`; `learning_materials` — **done (Phase 8)**
10. Laravel `notifications` — **done (Phase 8)**; `audit_logs` remains Phase 9
