<?php

namespace Database\Seeders;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\Campus;
use App\Models\ClassSection;
use App\Models\ClassSectionOffering;
use App\Models\Subject;
use App\Models\SubjectOffering;
use Illuminate\Database\Seeder;

class ClassSectionOfferingSeeder extends Seeder
{
    public function run(): void
    {
        $session = AcademicSession::query()->where('status', SessionStatus::Active)->first()
            ?? AcademicSession::query()->orderByDesc('starts_on')->first();
        $campus = Campus::query()->where('name', 'Owerri')->first();

        if ($session === null || $campus === null) {
            return;
        }

        $core = Subject::query()->whereIn('name', ['English Language', 'Mathematics'])->pluck('id');

        $byLevel = [
            'nursery' => Subject::query()->whereIn('name', ['Literary Studies', 'Quantitative Reasoning'])->pluck('id'),
            'primary' => Subject::query()->whereIn('name', ['Literary Studies', 'Quantitative Reasoning', 'Basic Science', 'Social Studies'])->pluck('id'),
            'jss' => Subject::query()->whereIn('name', ['Basic Science', 'Basic Technology', 'Social Studies', 'Civic Education', 'Computer Studies'])->pluck('id'),
            'ss' => Subject::query()->whereIn('name', ['Biology', 'Chemistry', 'Physics', 'Government', 'Literature in English'])->pluck('id'),
        ];

        ClassSection::query()->with('schoolClass.level')->orderBy('id')->each(function (ClassSection $section) use ($session, $campus, $core, $byLevel): void {
            $offering = ClassSectionOffering::query()->updateOrCreate(
                [
                    'class_section_id' => $section->id,
                    'academic_session_id' => $session->id,
                ],
                [
                    'campus_id' => $campus->id,
                    'capacity' => 30,
                    'is_active' => true,
                ],
            );

            $levelSlug = $section->schoolClass?->level?->slug;
            $subjectIds = $core->merge($byLevel[$levelSlug] ?? collect())->unique();

            foreach ($subjectIds as $subjectId) {
                SubjectOffering::query()->updateOrCreate(
                    [
                        'class_section_offering_id' => $offering->id,
                        'subject_id' => $subjectId,
                    ],
                    [],
                );
            }
        });
    }
}
