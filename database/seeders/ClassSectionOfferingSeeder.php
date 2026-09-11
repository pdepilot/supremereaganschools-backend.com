<?php

namespace Database\Seeders;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\Campus;
use App\Models\ClassSection;
use App\Models\ClassSectionOffering;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Support\SchoolBookStructure;
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

        $bookFormNames = SchoolBookStructure::formNames();

        $byForm = [
            'activity' => $this->subjectIds(SchoolBookStructure::defaultSubjectNames('Activity 1', 'activity')),
            'nursery' => $this->subjectIds(SchoolBookStructure::defaultSubjectNames('Nursery 1', 'nursery')),
            'nursery-2' => $this->subjectIds(SchoolBookStructure::defaultSubjectNames('Nursery 2')),
            'nursery-3' => $this->subjectIds(SchoolBookStructure::defaultSubjectNames('Nursery 3')),
            'basic-1-3' => $this->subjectIds(SchoolBookStructure::defaultSubjectNames('Basic 1')),
            'basic-4-5' => $this->subjectIds(SchoolBookStructure::defaultSubjectNames('Basic 4')),
            'jss' => $this->subjectIds(SchoolBookStructure::defaultSubjectNames('JSS 1', 'jss')),
        ];

        ClassSection::query()
            ->where('is_active', true)
            ->whereIn('name', $bookFormNames)
            ->with('schoolClass.level')
            ->orderBy('id')
            ->each(function (ClassSection $section) use ($session, $campus, $byForm): void {
                $class = $section->schoolClass;
                if ($class === null || $class->is_active === false) {
                    return;
                }

                if (! in_array($class->level?->slug, SchoolBookStructure::LEVEL_SLUGS, true)) {
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

        // Hide offerings that are not part of the school-book forms.
        ClassSectionOffering::query()
            ->where(function ($q) use ($bookFormNames): void {
                $q->whereHas('classSection', fn ($s) => $s->whereNotIn('name', $bookFormNames))
                    ->orWhereHas('classSection', fn ($s) => $s->where('is_active', false))
                    ->orWhereHas('classSection.schoolClass', fn ($c) => $c->where('is_active', false))
                    ->orWhereHas('classSection.schoolClass.level', fn ($l) => $l->whereNotIn('slug', SchoolBookStructure::LEVEL_SLUGS));
            })
            ->update(['is_active' => false]);
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

        if (preg_match('/^JSS\s+1\b/i', $className) === 1) {
            return $byForm['jss'];
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
