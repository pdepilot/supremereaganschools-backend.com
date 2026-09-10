<?php

namespace Database\Seeders;

use App\Models\ClassSection;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Support\SchoolBookStructure;
use Illuminate\Database\Seeder;

class SchoolClassSeeder extends Seeder
{
    public function run(): void
    {
        $keptClassIds = [];

        foreach (SchoolBookStructure::classesByLevel() as $slug => $classes) {
            $level = Level::query()->where('slug', $slug)->first();

            if ($level === null) {
                continue;
            }

            foreach (array_values($classes) as $index => $classData) {
                $class = SchoolClass::query()->updateOrCreate(
                    ['level_id' => $level->id, 'name' => $classData['name']],
                    [
                        'short_code' => $classData['short_code'],
                        'sort_order' => $index + 1,
                        'is_active' => true,
                    ],
                );

                $keptClassIds[] = $class->id;
                $keptSectionIds = [];

                foreach ($classData['arms'] as $arm) {
                    $display = $arm === ''
                        ? $class->name
                        : $class->name.' – '.$arm;

                    $section = ClassSection::query()->updateOrCreate(
                        ['school_class_id' => $class->id, 'arm' => $arm],
                        ['name' => $display, 'is_active' => true],
                    );

                    $keptSectionIds[] = $section->id;
                }

                ClassSection::query()
                    ->where('school_class_id', $class->id)
                    ->whereNotIn('id', $keptSectionIds)
                    ->update(['is_active' => false]);
            }
        }

        // Retire superseded class names that are no longer on the book (keep rows for FK history).
        SchoolClass::query()
            ->whereHas('level', fn ($query) => $query->whereIn('slug', SchoolBookStructure::LEVEL_SLUGS))
            ->whereNotIn('id', $keptClassIds)
            ->each(function (SchoolClass $class): void {
                $class->update(['is_active' => false]);
                $class->sections()->update(['is_active' => false]);
            });

        // Secondary forms are not part of the current Supreme Reagan class book.
        SchoolClass::query()
            ->whereHas('level', fn ($query) => $query->whereIn('slug', ['jss', 'ss']))
            ->each(function (SchoolClass $class): void {
                $class->update(['is_active' => false]);
                $class->sections()->update(['is_active' => false]);
            });

        // Any section not on the 18-form book must stay inactive.
        ClassSection::query()
            ->whereNotIn('name', SchoolBookStructure::formNames())
            ->update(['is_active' => false]);
    }
}
