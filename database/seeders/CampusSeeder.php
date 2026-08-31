<?php

namespace Database\Seeders;

use App\Models\Campus;
use Illuminate\Database\Seeder;

class CampusSeeder extends Seeder
{
    public function run(): void
    {
        Campus::query()->updateOrCreate(
            ['name' => 'Owerri'],
            [
                'address' => '15 Spibat Road, Amakohia-Akwakuma, Owerri',
                'is_active' => true,
            ],
        );
    }
}
