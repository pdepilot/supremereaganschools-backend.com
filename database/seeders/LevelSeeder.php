<?php

namespace Database\Seeders;

use App\Models\Level;
use Illuminate\Database\Seeder;

class LevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['name' => 'Activity', 'slug' => 'activity', 'description' => 'Activity / early years', 'sort_order' => 1],
            ['name' => 'Nursery', 'slug' => 'nursery', 'description' => 'Nursery wing', 'sort_order' => 2],
            ['name' => 'Primary', 'slug' => 'primary', 'description' => 'Basic / primary wing', 'sort_order' => 3],
            ['name' => 'Junior Secondary', 'slug' => 'jss', 'description' => 'Junior Secondary School', 'sort_order' => 4],
            ['name' => 'Senior Secondary', 'slug' => 'ss', 'description' => 'Senior Secondary School', 'sort_order' => 5],
        ];

        foreach ($levels as $level) {
            Level::query()->updateOrCreate(
                ['slug' => $level['slug']],
                [...$level, 'is_active' => true],
            );
        }
    }
}
