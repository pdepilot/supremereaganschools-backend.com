<?php

namespace Tests\Concerns;

use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\Campus;
use App\Models\ClassSection;
use App\Models\ClassSectionOffering;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;

trait CreatesAcademicContext
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function userWithRole(RoleSlug $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    protected function admin(): User
    {
        return $this->userWithRole(RoleSlug::SchoolAdmin);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function settings(array $attributes = []): SchoolSetting
    {
        return SchoolSetting::query()->updateOrCreate(
            ['id' => 1],
            array_merge([
                'name' => 'Supreme Reagan Schools',
                'timezone' => 'Africa/Lagos',
            ], $attributes),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function academicSession(array $attributes = []): AcademicSession
    {
        return AcademicSession::query()->create(array_merge([
            'name' => '2025/2026',
            'starts_on' => '2025-09-08',
            'ends_on' => '2026-07-24',
            'term_count' => 3,
            'status' => SessionStatus::Planned,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function termFor(AcademicSession $session, array $attributes = []): Term
    {
        return $session->terms()->create(array_merge([
            'name' => 'First Term',
            'term_number' => 1,
            'status' => SessionStatus::Planned,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function campus(array $attributes = []): Campus
    {
        return Campus::query()->create(array_merge([
            'name' => 'Owerri',
            'address' => '15 Spibat Road, Amakohia-Akwakuma, Owerri',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function level(array $attributes = []): Level
    {
        return Level::query()->create(array_merge([
            'name' => 'Junior Secondary',
            'slug' => 'jss',
            'sort_order' => 3,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function schoolClass(?Level $level = null, array $attributes = []): SchoolClass
    {
        $level ??= $this->level();

        return SchoolClass::query()->create(array_merge([
            'level_id' => $level->id,
            'name' => 'JSS 1',
            'short_code' => 'J1',
            'sort_order' => 1,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function section(?SchoolClass $class = null, array $attributes = []): ClassSection
    {
        $class ??= $this->schoolClass();

        return ClassSection::query()->create(array_merge([
            'school_class_id' => $class->id,
            'arm' => 'A',
            'name' => $class->name.' A',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function subject(array $attributes = []): Subject
    {
        return Subject::query()->create(array_merge([
            'name' => 'English Language',
            'code' => 'ENG',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function offering(?ClassSection $section = null, ?AcademicSession $session = null, ?Campus $campus = null, array $attributes = []): ClassSectionOffering
    {
        return ClassSectionOffering::query()->create(array_merge([
            'class_section_id' => ($section ?? $this->section())->id,
            'academic_session_id' => ($session ?? $this->academicSession())->id,
            'campus_id' => ($campus ?? $this->campus())->id,
            'capacity' => 30,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function department(array $attributes = []): \App\Models\Department
    {
        return \App\Models\Department::query()->create(array_merge([
            'name' => 'Mathematics',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function staff(?User $user = null, array $attributes = []): \App\Models\StaffProfile
    {
        $user ??= $this->userWithRole(RoleSlug::Teacher);

        return \App\Models\StaffProfile::query()->create(array_merge([
            'user_id' => $user->id,
            'staff_number' => 'SRS/TCH/'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT).substr((string) $user->id, -2),
            'status' => \App\Enums\StaffStatus::Active,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function student(?User $user = null, array $attributes = []): \App\Models\StudentProfile
    {
        $user ??= $this->userWithRole(RoleSlug::Student);
        $seq = str_pad((string) $user->id, 4, '0', STR_PAD_LEFT);

        return \App\Models\StudentProfile::query()->create(array_merge([
            'user_id' => $user->id,
            'admission_number' => 'SRS/2025/'.$seq,
            'surname' => 'Okafor',
            'first_name' => 'Chiamaka',
            'gender' => \App\Enums\Gender::Female,
            'status' => \App\Enums\StudentStatus::Active,
            'admitted_on' => '2025-09-08',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function guardian(?User $user = null, array $attributes = []): \App\Models\GuardianProfile
    {
        return \App\Models\GuardianProfile::query()->create(array_merge([
            'user_id' => $user?->id,
            'full_name' => 'Mrs. Okafor',
            'phone' => '08030000001',
            'email' => $user?->email ?? 'okafor@example.test',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function linkGuardian(\App\Models\GuardianProfile $guardian, \App\Models\StudentProfile $student, array $attributes = []): \App\Models\GuardianStudent
    {
        return \App\Models\GuardianStudent::query()->create(array_merge([
            'guardian_profile_id' => $guardian->id,
            'student_profile_id' => $student->id,
            'relationship' => \App\Enums\GuardianRelationship::Mother,
            'is_primary' => true,
            'can_login' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function enroll(\App\Models\StudentProfile $student, ?\App\Models\ClassSectionOffering $offering = null, array $attributes = []): \App\Models\Enrollment
    {
        $offering ??= $this->offering();

        return \App\Models\Enrollment::query()->create(array_merge([
            'student_profile_id' => $student->id,
            'class_section_offering_id' => $offering->id,
            'academic_session_id' => $offering->academic_session_id,
            'status' => \App\Enums\EnrollmentStatus::Active,
            'enrolled_on' => '2025-09-08',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function subjectOffering(?\App\Models\ClassSectionOffering $offering = null, ?\App\Models\Subject $subject = null, array $attributes = []): \App\Models\SubjectOffering
    {
        return \App\Models\SubjectOffering::query()->create(array_merge([
            'class_section_offering_id' => ($offering ?? $this->offering())->id,
            'subject_id' => ($subject ?? $this->subject())->id,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function classTeacher(\App\Models\StaffProfile $staff, \App\Models\ClassSectionOffering $offering, array $attributes = []): \App\Models\ClassTeacherAssignment
    {
        return \App\Models\ClassTeacherAssignment::query()->create(array_merge([
            'staff_profile_id' => $staff->id,
            'class_section_offering_id' => $offering->id,
            'is_active' => true,
            'assigned_on' => '2025-09-08',
        ], $attributes));
    }

    protected function otherOffering(\App\Models\ClassSectionOffering $home): \App\Models\ClassSectionOffering
    {
        $level = $home->classSection->schoolClass->level;
        $section = $this->section(
            $this->schoolClass($level, ['name' => 'JSS 2', 'short_code' => 'J2']),
            ['arm' => 'B', 'name' => 'JSS 2 B'],
        );

        return $this->offering($section, $home->academicSession, $home->campus);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function feeType(array $attributes = []): \App\Models\FeeType
    {
        return \App\Models\FeeType::query()->create(array_merge([
            'name' => 'Tuition',
            'code' => 'TUI'.substr((string) uniqid(), -4),
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function feeStructure(?\App\Models\FeeType $type = null, ?AcademicSession $session = null, ?Term $term = null, array $attributes = []): \App\Models\FeeStructure
    {
        $session ??= $this->academicSession();

        return \App\Models\FeeStructure::query()->create(array_merge([
            'fee_type_id' => ($type ?? $this->feeType())->id,
            'academic_session_id' => $session->id,
            'term_id' => $term?->id,
            'amount_kobo' => 18000000,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function subjectTeacher(\App\Models\StaffProfile $staff, \App\Models\SubjectOffering $offering, array $attributes = []): \App\Models\SubjectTeacherAssignment
    {
        return \App\Models\SubjectTeacherAssignment::query()->create(array_merge([
            'staff_profile_id' => $staff->id,
            'subject_offering_id' => $offering->id,
            'is_active' => true,
            'assigned_on' => '2025-09-08',
        ], $attributes));
    }

    /**
     * @return array{types: array<string, \App\Models\AssessmentType>, scales: \Illuminate\Support\Collection<int, \App\Models\GradeScale>}
     */
    protected function assessmentCatalogue(): array
    {
        $types = [];

        foreach ([
            ['kind' => \App\Enums\AssessmentKind::FirstCa, 'name' => 'First CA', 'max_score' => 15, 'sort_order' => 1],
            ['kind' => \App\Enums\AssessmentKind::SecondCa, 'name' => 'Second CA', 'max_score' => 15, 'sort_order' => 2],
            ['kind' => \App\Enums\AssessmentKind::Examination, 'name' => 'Examination', 'max_score' => 70, 'sort_order' => 3],
        ] as $row) {
            $types[$row['kind']->value] = \App\Models\AssessmentType::query()->updateOrCreate(
                ['kind' => $row['kind']],
                [
                    'name' => $row['name'],
                    'max_score' => $row['max_score'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                ],
            );
        }

        $scales = collect();
        foreach ([
            ['min_score' => 75, 'max_score' => 100, 'grade' => 'A', 'remark' => 'Excellent', 'sort_order' => 1],
            ['min_score' => 65, 'max_score' => 74.99, 'grade' => 'B', 'remark' => 'Very Good', 'sort_order' => 2],
            ['min_score' => 50, 'max_score' => 64.99, 'grade' => 'C', 'remark' => 'Good', 'sort_order' => 3],
            ['min_score' => 40, 'max_score' => 49.99, 'grade' => 'D', 'remark' => 'Fair', 'sort_order' => 4],
            ['min_score' => 0, 'max_score' => 39.99, 'grade' => 'F', 'remark' => 'Needs Support', 'sort_order' => 5],
        ] as $row) {
            $scales->push(\App\Models\GradeScale::query()->updateOrCreate(
                ['grade' => $row['grade']],
                $row,
            ));
        }

        return ['types' => $types, 'scales' => $scales];
    }
}
