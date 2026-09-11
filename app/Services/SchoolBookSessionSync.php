<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\Campus;
use App\Models\ClassSection;
use App\Models\ClassSectionOffering;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Support\SchoolBookStructure;
use Database\Seeders\LevelSeeder;
use Database\Seeders\SchoolClassSeeder;
use Database\Seeders\SubjectSeeder;
use Illuminate\Support\Carbon;

class SchoolBookSessionSync
{
    /**
     * Find or create an academic session, its three terms, and offerings for every school-book form.
     */
    public function ensure(string $name, ?int $createdBy = null): AcademicSession
    {
        $name = trim($name);
        $session = AcademicSession::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($session === null) {
            [$startsOn, $endsOn] = $this->inferSessionDates($name);
            $session = AcademicSession::query()->create([
                'name' => $name,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'term_count' => 3,
                'status' => SessionStatus::Planned,
                'created_by' => $createdBy,
            ]);
        }

        foreach ([1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'] as $number => $termName) {
            Term::query()->firstOrCreate(
                [
                    'academic_session_id' => $session->id,
                    'term_number' => $number,
                ],
                [
                    'name' => $termName,
                    'status' => SessionStatus::Planned,
                ],
            );
        }

        $this->syncBookOfferings((int) $session->id);

        return $session->fresh(['terms']) ?? $session;
    }

    /**
     * Ensure school-book classes/sections exist, then open offerings for the session.
     */
    public function ensureBookForSession(int $sessionId): void
    {
        $expectedForms = count(SchoolBookStructure::formNames());
        $activeForms = ClassSection::query()
            ->where('is_active', true)
            ->whereIn('name', SchoolBookStructure::formNames())
            ->whereHas('schoolClass', function ($q): void {
                $q->where('is_active', true)
                    ->whereIn('name', SchoolBookStructure::schoolClassNames())
                    ->whereHas('level', fn ($l) => $l->whereIn('slug', SchoolBookStructure::LEVEL_SLUGS));
            })
            ->count();

        if ($activeForms < $expectedForms) {
            app(LevelSeeder::class)->run();
            app(SubjectSeeder::class)->run();
            app(SchoolClassSeeder::class)->run();
        }

        $this->syncBookOfferings($sessionId);
    }

    public function syncBookOfferings(int $sessionId): void
    {
        $campusId = Campus::query()->where('name', 'Owerri')->value('id')
            ?? Campus::query()->where('is_active', true)->value('id')
            ?? Campus::query()->value('id');

        ClassSection::query()
            ->where('is_active', true)
            ->whereIn('name', SchoolBookStructure::formNames())
            ->with('schoolClass.level')
            ->whereHas('schoolClass', function ($q): void {
                $q->where('is_active', true)
                    ->whereIn('name', SchoolBookStructure::schoolClassNames())
                    ->whereHas('level', fn ($l) => $l->whereIn('slug', SchoolBookStructure::LEVEL_SLUGS));
            })
            ->each(function (ClassSection $section) use ($sessionId, $campusId): void {
                $template = ClassSectionOffering::query()
                    ->where('class_section_id', $section->id)
                    ->where('academic_session_id', '!=', $sessionId)
                    ->with('subjectOfferings')
                    ->orderByDesc('id')
                    ->first();

                $offering = ClassSectionOffering::query()->firstOrCreate(
                    [
                        'class_section_id' => $section->id,
                        'academic_session_id' => $sessionId,
                    ],
                    [
                        'campus_id' => $template?->campus_id ?? $campusId,
                        'capacity' => $template?->capacity ?? 30,
                        'is_active' => true,
                    ],
                );

                if (! $offering->is_active) {
                    $offering->update(['is_active' => true]);
                }

                if ($offering->subjectOfferings()->exists()) {
                    return;
                }

                if ($template && $template->subjectOfferings->isNotEmpty()) {
                    foreach ($template->subjectOfferings as $subjectOffering) {
                        SubjectOffering::query()->firstOrCreate([
                            'class_section_offering_id' => $offering->id,
                            'subject_id' => $subjectOffering->subject_id,
                        ]);
                    }

                    return;
                }

                $className = $section->schoolClass?->name ?? $section->name;
                $levelSlug = $section->schoolClass?->level?->slug;
                $names = SchoolBookStructure::defaultSubjectNames($className, $levelSlug);
                if ($names === []) {
                    return;
                }

                $subjectIds = Subject::query()
                    ->whereIn('name', $names)
                    ->where('is_active', true)
                    ->pluck('id');

                foreach ($subjectIds as $subjectId) {
                    SubjectOffering::query()->firstOrCreate([
                        'class_section_offering_id' => $offering->id,
                        'subject_id' => (int) $subjectId,
                    ]);
                }
            });
    }

    public function alignOfferingToSession(ClassSectionOffering $offering, int $sessionId): ClassSectionOffering
    {
        if ((int) $offering->academic_session_id === $sessionId) {
            return $offering;
        }

        $offering->loadMissing('subjectOfferings');

        $aligned = ClassSectionOffering::query()->firstOrCreate(
            [
                'class_section_id' => $offering->class_section_id,
                'academic_session_id' => $sessionId,
            ],
            [
                'campus_id' => $offering->campus_id,
                'capacity' => $offering->capacity,
                'is_active' => true,
            ],
        );

        if ($aligned->wasRecentlyCreated || ! $aligned->subjectOfferings()->exists()) {
            foreach ($offering->subjectOfferings as $subjectOffering) {
                SubjectOffering::query()->firstOrCreate([
                    'class_section_offering_id' => $aligned->id,
                    'subject_id' => $subjectOffering->subject_id,
                ]);
            }
        }

        if (! $aligned->is_active) {
            $aligned->update(['is_active' => true]);
        }

        return $aligned;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function inferSessionDates(string $name): array
    {
        if (preg_match('/(\d{4})\s*[\/\-]\s*(\d{4})/', $name, $matches) === 1) {
            return [
                sprintf('%04d-09-01', (int) $matches[1]),
                sprintf('%04d-07-31', (int) $matches[2]),
            ];
        }

        $year = (int) Carbon::now()->year;

        return [
            sprintf('%04d-09-01', $year),
            sprintf('%04d-07-31', $year + 1),
        ];
    }
}
