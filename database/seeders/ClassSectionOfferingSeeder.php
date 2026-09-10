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
use Illuminate\Support\Collection;

class ClassSectionOfferingSeeder extends Seeder
{
    public function run(): void
    {
        $session = AcademicSession::query()->where('status', SessionStatus::Active)->first()
            ?? AcademicSession::query()->orderByDesc('starts_on')->first();
        $campus = Campus::query()->where('name', 'Owerri')->first()
            ?? Campus::query()->where('is_active', true)->first()
            ?? Campus::query()->first();

        if ($session === null || $campus === null) {
            return;
        }

        $basicLowerSubjects = $this->subjectIds([
            'Mathematics',
            'English',
            'Diction',
            'Abacus',
            'Social Studies',
            'French',
            'Basic Science',
            'Christian Religious Studies',
            'Physical and Health Education',
            'Computer',
            'Home Economics',
            'Vocational Aptitude',
            'Cultural and Creative Arts',
            'Writing',
            'Music',
            'Agricultural Science',
            'Quantitative Reasoning',
            'Verbal Reasoning',
            'Literature',
            'Coding',
            'Civic Education',
            'Igbo',
        ]);

        $basicUpperSubjects = $this->subjectIds([
            'Mathematics',
            'English',
            'Diction',
            'Abacus',
            'Social Studies',
            'French',
            'Basic Science',
            'Christian Religious Studies',
            'Physical and Health Education',
            'Computer',
            'Home Economics',
            'Cultural and Creative Arts',
            'Music',
            'Agricultural Science',
            'Quantitative Reasoning',
            'Verbal Reasoning',
            'Literature',
            'Coding',
            'Civic Education',
            'Igbo',
        ]);

        $nursery2Subjects = $this->subjectIds([
            'Discover Numeracy',
            'Discover Literacy',
            'Discover Me',
            'Numeracy Thinking',
            'Literacy Thinking',
            'Computer',
            'Christian Religious Studies',
            'Igbo',
            'Health Habit',
            'Social Habit',
            'Calligraphy',
            'Colouring',
            'Diction',
            'Literature',
            'Writing',
        ]);

        $nursery3Subjects = $this->subjectIds([
            'Discover Numeracy',
            'Discover Literacy',
            'Numeracy Thinking',
            'Literacy Thinking',
            'Calligraphy',
            'Writing',
            'Health Habit',
            'Social Habit',
            'Discovery Science',
            'Computer Science',
            'Christian Religious Studies',
            'Phonics',
            'Literature',
            'Igbo',
            'Diction',
            'Colour Me',
        ]);

        $byForm = [
            'activity' => $this->subjectIds(['English', 'Mathematics', 'Quantitative Reasoning', 'Writing', 'Music']),
            'nursery' => $this->subjectIds(['English', 'Mathematics', 'Quantitative Reasoning', 'Writing', 'Music', 'Diction']),
            'nursery-2' => $nursery2Subjects,
            'nursery-3' => $nursery3Subjects,
            'basic-1-3' => $basicLowerSubjects,
            'basic-4-5' => $basicUpperSubjects,
            'jss' => $this->subjectIds(['Mathematics', 'English', 'Basic Science', 'Basic Technology', 'Social Studies', 'Civic Education', 'Computer']),
            'ss' => $this->subjectIds(['Mathematics', 'English', 'Biology', 'Chemistry', 'Physics', 'Government', 'Literature in English']),
        ];

        ClassSection::query()
            ->where('is_active', true)
            ->with('schoolClass.level')
            ->orderBy('id')
            ->each(function (ClassSection $section) use ($session, $campus, $byForm): void {
                $class = $section->schoolClass;
                if ($class === null || $class->is_active === false) {
                    return;
                }

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

                $subjectIds = $this->subjectsForClass($class->name, $class->level?->slug, $byForm);

                // Replace the offered set so retired subjects fall off Basic forms.
                SubjectOffering::query()
                    ->where('class_section_offering_id', $offering->id)
                    ->whereNotIn('subject_id', $subjectIds)
                    ->delete();

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

    /**
     * @param  array<string, Collection<int, int>>  $byForm
     * @return Collection<int, int>
     */
    private function subjectsForClass(string $className, ?string $levelSlug, array $byForm): Collection
    {
        if (preg_match('/^Nursery\s+2\b/i', $className) === 1) {
            return $byForm['nursery-2'];
        }

        if (preg_match('/^Nursery\s+3\b/i', $className) === 1) {
            return $byForm['nursery-3'];
        }

        if (preg_match('/^Basic\s+[123]\b/i', $className) === 1) {
            return $byForm['basic-1-3'];
        }

        if (preg_match('/^Basic\s+[45]\b/i', $className) === 1) {
            return $byForm['basic-4-5'];
        }

        return $byForm[$levelSlug] ?? collect();
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, int>
     */
    private function subjectIds(array $names): Collection
    {
        return Subject::query()
            ->whereIn('name', $names)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('id');
    }
}
