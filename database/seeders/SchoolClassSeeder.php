<?php

namespace Database\Seeders;

use App\Models\ClassSection;
use App\Models\Level;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;

class SchoolClassSeeder extends Seeder
{
    public function run(): void
    {
        $structure = [
            'activity' => [
                ['name' => 'Activity 1', 'short_code' => 'A1', 'arms' => ['']],
                ['name' => 'Activity 2', 'short_code' => 'A2', 'arms' => ['Blossom', 'Excel']],
            ],
            'nursery' => [
                ['name' => 'Nursery 1', 'short_code' => 'N1', 'arms' => ['Achiever', 'Fabulous']],
                ['name' => 'Nursery 2', 'short_code' => 'N2', 'arms' => ['Awesome', 'Amazing']],
                ['name' => 'Nursery 3', 'short_code' => 'N3', 'arms' => ['Gold', 'Fruitful']],
            ],
            'primary' => [
                ['name' => 'Basic 1', 'short_code' => 'B1', 'arms' => ['Amazing', 'Excellent']],
                ['name' => 'Basic 2', 'short_code' => 'B2', 'arms' => ['Elegant', 'Pacesetters']],
                ['name' => 'Basic 3', 'short_code' => 'B3', 'arms' => ['Zion', 'Rising Stars']],
                ['name' => 'Basic 4', 'short_code' => 'B4', 'arms' => ['Brilliant', 'Victorious']],
                ['name' => 'Basic 5', 'short_code' => 'B5', 'arms' => ['Diamonds']],
            ],
        ];

        $keptClassIds = [];

        foreach ($structure as $slug => $classes) {
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
            ->whereHas('level', fn ($query) => $query->whereIn('slug', ['activity', 'nursery', 'primary']))
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
    }
}
