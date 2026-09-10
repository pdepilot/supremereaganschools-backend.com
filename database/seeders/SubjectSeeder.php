<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $departments = ['Mathematics', 'Languages', 'Sciences', 'Arts', 'Primary', 'ICT', 'Vocational'];

        foreach ($departments as $name) {
            Department::query()->updateOrCreate(['name' => $name], ['is_active' => true]);
        }

        $subjects = [
            // Basic 1–3 (and shared primary) book
            ['name' => 'Mathematics', 'code' => 'MTH', 'department' => 'Mathematics'],
            ['name' => 'English', 'code' => 'ENG', 'department' => 'Languages'],
            ['name' => 'Diction', 'code' => 'DIC', 'department' => 'Languages'],
            ['name' => 'Abacus', 'code' => 'ABC', 'department' => 'Mathematics'],
            ['name' => 'Social Studies', 'code' => 'SOS', 'department' => 'Arts'],
            ['name' => 'French', 'code' => 'FRE', 'department' => 'Languages'],
            ['name' => 'Basic Science', 'code' => 'BSC', 'department' => 'Sciences'],
            ['name' => 'Christian Religious Studies', 'code' => 'CRS', 'department' => 'Arts'],
            ['name' => 'Physical and Health Education', 'code' => 'PHE', 'department' => 'Arts'],
            ['name' => 'Computer', 'code' => 'CMP', 'department' => 'ICT'],
            ['name' => 'Home Economics', 'code' => 'HEC', 'department' => 'Vocational'],
            ['name' => 'Vocational Aptitude', 'code' => 'VAP', 'department' => 'Vocational'],
            ['name' => 'Cultural and Creative Arts', 'code' => 'CCA', 'department' => 'Arts'],
            ['name' => 'Writing', 'code' => 'WRT', 'department' => 'Languages'],
            ['name' => 'Music', 'code' => 'MUS', 'department' => 'Arts'],
            ['name' => 'Agricultural Science', 'code' => 'AGR', 'department' => 'Sciences'],
            ['name' => 'Quantitative Reasoning', 'code' => 'QR', 'department' => 'Primary'],
            ['name' => 'Verbal Reasoning', 'code' => 'VR', 'department' => 'Primary'],
            ['name' => 'Literature', 'code' => 'LIT', 'department' => 'Languages'],
            ['name' => 'Coding', 'code' => 'COD', 'department' => 'ICT'],
            ['name' => 'Civic Education', 'code' => 'CIV', 'department' => 'Arts'],
            ['name' => 'Igbo', 'code' => 'IGB', 'department' => 'Languages'],

            // Nursery 3 book
            ['name' => 'Discover Numeracy', 'code' => 'DNUM', 'department' => 'Primary'],
            ['name' => 'Discover Literacy', 'code' => 'DLIT', 'department' => 'Primary'],
            ['name' => 'Numeracy Thinking', 'code' => 'NTHK', 'department' => 'Primary'],
            ['name' => 'Literacy Thinking', 'code' => 'LTHK', 'department' => 'Primary'],
            ['name' => 'Calligraphy', 'code' => 'CAL', 'department' => 'Arts'],
            ['name' => 'Health Habit', 'code' => 'HHAB', 'department' => 'Primary'],
            ['name' => 'Social Habit', 'code' => 'SHAB', 'department' => 'Primary'],
            ['name' => 'Discovery Science', 'code' => 'DSCL', 'department' => 'Sciences'],
            ['name' => 'Computer Science', 'code' => 'CSCI', 'department' => 'ICT'],
            ['name' => 'Phonics', 'code' => 'PHO', 'department' => 'Languages'],
            ['name' => 'Colour Me', 'code' => 'COLM', 'department' => 'Arts'],
            ['name' => 'Discover Me', 'code' => 'DME', 'department' => 'Sciences'],
            ['name' => 'Colouring', 'code' => 'COLR', 'department' => 'Arts'],

            // Kept for secondary / future forms
            ['name' => 'Basic Technology', 'code' => 'BTE', 'department' => 'Sciences'],
            ['name' => 'Biology', 'code' => 'BIO', 'department' => 'Sciences'],
            ['name' => 'Chemistry', 'code' => 'CHM', 'department' => 'Sciences'],
            ['name' => 'Physics', 'code' => 'PHY', 'department' => 'Sciences'],
            ['name' => 'Government', 'code' => 'GOV', 'department' => 'Arts'],
            ['name' => 'Literature in English', 'code' => 'LITENG', 'department' => 'Languages'],

            // JSS 1 book
            ['name' => 'English Studies', 'code' => 'ENS', 'department' => 'Languages'],
            ['name' => 'Intermediate Science', 'code' => 'ISCI', 'department' => 'Sciences'],
            ['name' => 'Digital Technology', 'code' => 'DTEC', 'department' => 'ICT'],
            ['name' => 'Social and Citizenship Studies', 'code' => 'SCS', 'department' => 'Arts'],
            ['name' => 'Solar PV', 'code' => 'SPV', 'department' => 'Vocational'],
            ['name' => 'Fashion Design', 'code' => 'FDES', 'department' => 'Vocational'],
            ['name' => 'Business Studies', 'code' => 'BUS', 'department' => 'Vocational'],
            ['name' => 'Nigerian History', 'code' => 'NHIS', 'department' => 'Arts'],
            ['name' => 'Cambridge Science', 'code' => 'CAMS', 'department' => 'Sciences'],
            ['name' => 'Coding and Robotics', 'code' => 'CORT', 'department' => 'ICT'],
        ];

        foreach ($subjects as $subject) {
            $departmentId = Department::query()->where('name', $subject['department'])->value('id');

            // Prefer stable codes so renamed school subjects (e.g. English) replace older labels.
            Subject::query()->updateOrCreate(
                ['code' => $subject['code']],
                [
                    'name' => $subject['name'],
                    'department_id' => $departmentId,
                    'is_active' => true,
                ],
            );
        }

        // Retire superseded catalogue labels that no longer match the school book.
        Subject::query()
            ->whereIn('code', ['ICT'])
            ->orWhereIn('name', ['English Language', 'Computer Studies', 'Literary Studies'])
            ->whereNotIn('code', collect($subjects)->pluck('code')->all())
            ->update(['is_active' => false]);
    }
}
