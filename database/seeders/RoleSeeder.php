<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->roles() as $role) {
            Role::query()->updateOrCreate(
                ['slug' => $role['slug']->value],
                [
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'is_system_role' => $role['slug']->isSystemRole(),
                ],
            );
        }
    }

    /**
     * @return list<array{name: string, slug: RoleSlug, description: string}>
     */
    private function roles(): array
    {
        return [
            ['name' => 'Super Admin', 'slug' => RoleSlug::SuperAdmin, 'description' => 'Full school authority.'],
            ['name' => 'School Admin', 'slug' => RoleSlug::SchoolAdmin, 'description' => 'School operations desk.'],
            ['name' => 'Principal', 'slug' => RoleSlug::Principal, 'description' => 'Head of school command desk.'],
            ['name' => 'Vice Principal', 'slug' => RoleSlug::VicePrincipal, 'description' => 'Deputy head academic desk.'],
            ['name' => 'Examination Officer', 'slug' => RoleSlug::ExaminationOfficer, 'description' => 'Exams and marks desk.'],
            ['name' => 'Admissions Officer', 'slug' => RoleSlug::AdmissionsOfficer, 'description' => 'Admissions and enrolment desk.'],
            ['name' => 'Content Manager', 'slug' => RoleSlug::ContentManager, 'description' => 'Website and notices desk.'],
            ['name' => 'Teacher', 'slug' => RoleSlug::Teacher, 'description' => 'Teaching staff portal.'],
            ['name' => 'Accountant', 'slug' => RoleSlug::Accountant, 'description' => 'Fees and payments desk.'],
            ['name' => 'Staff', 'slug' => RoleSlug::Staff, 'description' => 'Non-teaching staff desk.'],
            ['name' => 'Parent', 'slug' => RoleSlug::Parent, 'description' => 'Parent / guardian portal.'],
            ['name' => 'Student', 'slug' => RoleSlug::Student, 'description' => 'Student portal.'],
        ];
    }
}
