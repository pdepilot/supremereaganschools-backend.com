<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            LocalAdminSeeder::class,
            AcademicStructureSeeder::class,
            PeopleSeeder::class,
            AttendanceSeeder::class,
            FeesSeeder::class,
            AssessmentSeeder::class,
            AdmissionsSeeder::class,
            ClassroomSeeder::class,
            EmailTemplateSeeder::class,
            NewsInsightsSeeder::class,
        ]);
    }
}
