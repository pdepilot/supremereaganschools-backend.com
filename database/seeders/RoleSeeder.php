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
            ['name' => 'Teacher', 'slug' => RoleSlug::Teacher, 'description' => 'Teaching staff portal.'],
            ['name' => 'Parent', 'slug' => RoleSlug::Parent, 'description' => 'Parent / guardian portal.'],
            ['name' => 'Student', 'slug' => RoleSlug::Student, 'description' => 'Student portal.'],
            ['name' => 'Principal', 'slug' => RoleSlug::Principal, 'description' => 'Seeded for later use.'],
            ['name' => 'Vice Principal', 'slug' => RoleSlug::VicePrincipal, 'description' => 'Seeded for later use.'],
            ['name' => 'Accountant', 'slug' => RoleSlug::Accountant, 'description' => 'Seeded for later use.'],
            ['name' => 'Staff', 'slug' => RoleSlug::Staff, 'description' => 'Non-teaching staff. Seeded for later use.'],
        ];
    }
}
