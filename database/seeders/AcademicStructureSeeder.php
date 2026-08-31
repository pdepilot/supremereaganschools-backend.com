<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class AcademicStructureSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CampusSeeder::class,
            LevelSeeder::class,
            SchoolClassSeeder::class,
            AcademicSessionSeeder::class,
            SubjectSeeder::class,
            SchoolSettingSeeder::class,
            ClassSectionOfferingSeeder::class,
        ]);
    }
}
