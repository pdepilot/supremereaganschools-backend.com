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
            'nursery' => [
                ['name' => 'Nursery 1', 'short_code' => 'N1', 'arms' => ['']],
                ['name' => 'Nursery 2', 'short_code' => 'N2', 'arms' => ['']],
            ],
            'primary' => $this->numbered('Primary', 'P', 6, ['A', 'B']),
            'jss' => $this->numbered('JSS', 'J', 3, ['A', 'B']),
            'ss' => $this->numbered('SS', 'S', 3, ['A', 'B']),
        ];

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

                foreach ($classData['arms'] as $arm) {
                    $display = $arm === '' ? $class->name : $class->name.' '.$arm;

                    ClassSection::query()->updateOrCreate(
                        ['school_class_id' => $class->id, 'arm' => $arm],
                        ['name' => $display, 'is_active' => true],
                    );
                }
            }
        }
    }

    /**
     * @param  list<string>  $arms
     * @return list<array{name: string, short_code: string, arms: list<string>}>
     */
    private function numbered(string $name, string $code, int $count, array $arms): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'name' => $name.' '.$i,
                'short_code' => $code.$i,
                'arms' => $arms,
            ];
        }

        return $rows;
    }
}
