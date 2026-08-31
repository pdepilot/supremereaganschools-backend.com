<?php

namespace Database\Seeders;

use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\RoleSlug;
use App\Enums\StaffStatus;
use App\Enums\StudentStatus;
use App\Models\ClassSection;
use App\Models\ClassSectionOffering;
use App\Models\ClassTeacherAssignment;
use App\Models\Department;
use App\Models\GuardianProfile;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\SubjectTeacherAssignment;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\GuardianService;
use App\Services\StaffService;
use App\Services\StudentService;
use Illuminate\Database\Seeder;

class PeopleSeeder extends Seeder
{
    public function run(): void
    {
        $staff = app(StaffService::class);
        $students = app(StudentService::class);
        $guardians = app(GuardianService::class);
        $enrollments = app(EnrollmentService::class);

        $eze = $this->staff($staff, [
            'name' => 'Mrs. Eze',
            'email' => 'eze@supremereaganschools.test',
            'staff_number' => 'SRS/TCH/0012',
            'department' => 'Mathematics',
            'job_title' => 'Class Teacher',
            'phone' => '08030000012',
        ]);

        $okoroStaff = $this->staff($staff, [
            'name' => 'Mr. Daniel Okoro',
            'email' => 'dokoro@supremereaganschools.test',
            'staff_number' => 'SRS/TCH/0017',
            'department' => 'Sciences',
            'job_title' => 'Subject Teacher',
            'phone' => '08030000017',
        ]);

        $obi = $this->staff($staff, [
            'name' => 'Mrs. Cynthia Obi',
            'email' => 'obi@supremereaganschools.test',
            'staff_number' => 'SRS/TCH/0021',
            'department' => 'Languages',
            'job_title' => 'Subject Teacher',
            'phone' => '08030000021',
        ]);

        $ade = $this->staff($staff, [
            'name' => 'Mrs. Grace Ade',
            'email' => 'ade@supremereaganschools.test',
            'staff_number' => 'SRS/TCH/0035',
            'department' => 'Primary',
            'job_title' => 'Class Teacher',
            'phone' => '08030000035',
        ]);

        $chiamaka = $this->pupil($students, [
            'admission_number' => 'SRS/2025/0142',
            'surname' => 'Okafor',
            'first_name' => 'Chiamaka',
            'gender' => Gender::Female->value,
            'form' => 'JSS 2 A',
        ]);

        $daniel = $this->pupil($students, [
            'admission_number' => 'SRS/2025/0198',
            'surname' => 'Okoro',
            'first_name' => 'Daniel',
            'gender' => Gender::Male->value,
            'form' => 'Primary 4 B',
        ]);

        $adaeze = $this->pupil($students, [
            'admission_number' => 'SRS/2025/0221',
            'surname' => 'Nwosu',
            'first_name' => 'Adaeze',
            'gender' => Gender::Female->value,
            'form' => 'SS 1 B',
        ]);

        $this->guardian($guardians, [
            'full_name' => 'Mrs. Okafor',
            'email' => 'okafor@supremereaganschools.test',
            'phone' => '08031110001',
            'occupation' => 'Trader',
            'student' => $chiamaka,
            'relationship' => GuardianRelationship::Mother->value,
        ]);

        $this->guardian($guardians, [
            'full_name' => 'Mr. Okoro',
            'email' => 'okoro.parent@supremereaganschools.test',
            'phone' => '08031110002',
            'occupation' => 'Engineer',
            'student' => $daniel,
            'relationship' => GuardianRelationship::Father->value,
        ]);

        $this->guardian($guardians, [
            'full_name' => 'Mr. Nwosu',
            'email' => 'nwosu@supremereaganschools.test',
            'phone' => '08031110003',
            'occupation' => 'Civil servant',
            'student' => $adaeze,
            'relationship' => GuardianRelationship::Father->value,
        ]);

        foreach ([$chiamaka, $daniel, $adaeze] as $pupil) {
            if ($pupil === null || $pupil->enrollments()->exists()) {
                continue;
            }

            $form = $pupil->admission_number === 'SRS/2025/0142' ? 'JSS 2 A'
                : ($pupil->admission_number === 'SRS/2025/0198' ? 'Primary 4 B' : 'SS 1 B');
            $offering = $this->offeringNamed($form);

            if ($offering === null) {
                continue;
            }

            $enrollments->create([
                'student_profile_id' => $pupil->id,
                'class_section_offering_id' => $offering->id,
                'enrolled_on' => '2025-09-08',
            ]);
        }

        $this->assignClassTeacher($eze, 'JSS 2 A');
        $this->assignClassTeacher($ade, 'Primary 4 B');
        $this->assignSubjectTeacher($eze, 'JSS 2 A', 'Mathematics');
        $this->assignSubjectTeacher($okoroStaff, 'JSS 2 A', 'Basic Science');
        $this->assignSubjectTeacher($obi, 'SS 1 B', 'English Language');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function staff(StaffService $staff, array $data): ?StaffProfile
    {
        $existing = StaffProfile::query()->where('staff_number', $data['staff_number'])->first();

        if ($existing) {
            return $existing;
        }

        if (User::query()->where('email', $data['email'])->exists()) {
            return StaffProfile::query()->whereHas('user', fn ($query) => $query->where('email', $data['email']))->first();
        }

        return $staff->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => 'password',
            'role' => RoleSlug::Teacher->value,
            'staff_number' => $data['staff_number'],
            'department_id' => Department::query()->where('name', $data['department'])->value('id'),
            'job_title' => $data['job_title'],
            'phone' => $data['phone'],
            'employed_on' => '2022-09-01',
            'status' => StaffStatus::Active->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pupil(StudentService $students, array $data): ?StudentProfile
    {
        $existing = StudentProfile::query()->where('admission_number', $data['admission_number'])->first();

        if ($existing) {
            return $existing;
        }

        return $students->create([
            'admission_number' => $data['admission_number'],
            'surname' => $data['surname'],
            'first_name' => $data['first_name'],
            'gender' => $data['gender'],
            'status' => StudentStatus::Active->value,
            'admitted_on' => '2025-09-08',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guardian(GuardianService $guardians, array $data): ?GuardianProfile
    {
        $existing = GuardianProfile::query()->where('email', $data['email'])->first();

        if ($existing) {
            if ($data['student'] instanceof StudentProfile && ! $existing->students()->whereKey($data['student']->id)->exists()) {
                $guardians->link($existing, [
                    'student_profile_id' => $data['student']->id,
                    'relationship' => $data['relationship'],
                    'is_primary' => true,
                    'can_login' => true,
                ]);
            }

            return $existing->fresh(['students']);
        }

        if ($data['student'] === null) {
            return null;
        }

        return $guardians->create([
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'password' => 'password',
            'phone' => $data['phone'],
            'occupation' => $data['occupation'],
            'student_profile_id' => $data['student']->id,
            'relationship' => $data['relationship'],
            'is_primary' => true,
            'can_login' => true,
        ]);
    }

    private function assignClassTeacher(?StaffProfile $staff, string $form): void
    {
        $offering = $this->offeringNamed($form);

        if ($staff === null || $offering === null) {
            return;
        }

        ClassTeacherAssignment::query()->updateOrCreate(
            [
                'staff_profile_id' => $staff->id,
                'class_section_offering_id' => $offering->id,
            ],
            [
                'is_active' => true,
                'assigned_on' => '2025-09-08',
            ],
        );
    }

    private function assignSubjectTeacher(?StaffProfile $staff, string $form, string $subjectName): void
    {
        $offering = $this->offeringNamed($form);
        $subjectId = Subject::query()->where('name', $subjectName)->value('id');

        if ($staff === null || $offering === null || $subjectId === null) {
            return;
        }

        $subjectOffering = SubjectOffering::query()->where([
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subjectId,
        ])->first();

        if ($subjectOffering === null) {
            return;
        }

        SubjectTeacherAssignment::query()->updateOrCreate(
            [
                'staff_profile_id' => $staff->id,
                'subject_offering_id' => $subjectOffering->id,
            ],
            [
                'is_active' => true,
                'assigned_on' => '2025-09-08',
            ],
        );
    }

    private function offeringNamed(string $form): ?ClassSectionOffering
    {
        $section = ClassSection::query()->where('name', $form)->first();

        if ($section === null) {
            return null;
        }

        return ClassSectionOffering::query()
            ->where('class_section_id', $section->id)
            ->orderByDesc('id')
            ->first();
    }
}
