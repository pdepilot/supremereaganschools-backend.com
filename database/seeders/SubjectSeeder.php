<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $departments = ['Mathematics', 'Languages', 'Sciences', 'Arts', 'Primary', 'ICT'];

        foreach ($departments as $name) {
            Department::query()->updateOrCreate(['name' => $name], ['is_active' => true]);
        }

        $subjects = [
            ['name' => 'English Language', 'code' => 'ENG', 'department' => 'Languages'],
            ['name' => 'Mathematics', 'code' => 'MTH', 'department' => 'Mathematics'],
            ['name' => 'Basic Science', 'code' => 'BSC', 'department' => 'Sciences'],
            ['name' => 'Basic Technology', 'code' => 'BTE', 'department' => 'Sciences'],
            ['name' => 'Social Studies', 'code' => 'SOS', 'department' => 'Arts'],
            ['name' => 'Civic Education', 'code' => 'CIV', 'department' => 'Arts'],
            ['name' => 'Christian Religious Studies', 'code' => 'CRS', 'department' => 'Arts'],
            ['name' => 'Computer Studies', 'code' => 'ICT', 'department' => 'ICT'],
            ['name' => 'Physical and Health Education', 'code' => 'PHE', 'department' => 'Arts'],
            ['name' => 'Literary Studies', 'code' => 'LIT', 'department' => 'Primary'],
            ['name' => 'Quantitative Reasoning', 'code' => 'QR', 'department' => 'Primary'],
            ['name' => 'Biology', 'code' => 'BIO', 'department' => 'Sciences'],
            ['name' => 'Chemistry', 'code' => 'CHM', 'department' => 'Sciences'],
            ['name' => 'Physics', 'code' => 'PHY', 'department' => 'Sciences'],
            ['name' => 'Government', 'code' => 'GOV', 'department' => 'Arts'],
            ['name' => 'Literature in English', 'code' => 'LITENG', 'department' => 'Languages'],
        ];

        foreach ($subjects as $subject) {
            $departmentId = Department::query()->where('name', $subject['department'])->value('id');

            Subject::query()->updateOrCreate(
                ['name' => $subject['name']],
                [
                    'code' => $subject['code'],
                    'department_id' => $departmentId,
                    'is_active' => true,
                ],
            );
        }
    }
}
