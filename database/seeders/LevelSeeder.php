<?php

namespace Database\Seeders;

use App\Models\Level;
use Illuminate\Database\Seeder;

class LevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['name' => 'Nursery', 'slug' => 'nursery', 'description' => 'Early years', 'sort_order' => 1],
            ['name' => 'Primary', 'slug' => 'primary', 'description' => 'Primary school', 'sort_order' => 2],
            ['name' => 'Junior Secondary', 'slug' => 'jss', 'description' => 'Junior Secondary School', 'sort_order' => 3],
            ['name' => 'Senior Secondary', 'slug' => 'ss', 'description' => 'Senior Secondary School', 'sort_order' => 4],
        ];

        foreach ($levels as $level) {
            Level::query()->updateOrCreate(
                ['slug' => $level['slug']],
                [...$level, 'is_active' => true],
            );
        }
    }
}
