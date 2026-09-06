<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'title' => 'Open Day & Campus Tour',
                'summary' => 'Walk the classrooms, ICT centre, studios and grounds with our admissions team.',
                'category' => 'Admissions',
                'starts_at' => now()->addWeeks(6)->setTime(9, 0),
                'location' => 'Main campus · Owerri',
                'is_featured' => true,
            ],
            [
                'title' => 'Inter-House Sports Day',
                'summary' => 'Colour, pace and house pride on the field — families welcome to cheer every race and relay.',
                'category' => 'Sport',
                'starts_at' => now()->addWeeks(9)->setTime(8, 0),
                'location' => 'School sports ground',
                'is_featured' => false,
            ],
            [
                'title' => 'Cultural Day Showcase',
                'summary' => 'Music, drama, art and heritage from nursery through secondary — one afternoon of the whole house on stage.',
                'category' => 'Culture',
                'starts_at' => now()->addWeeks(11)->setTime(11, 0),
                'location' => 'Assembly hall',
                'is_featured' => false,
            ],
        ];

        foreach ($rows as $row) {
            $slug = Str::slug($row['title']);

            Event::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'title' => $row['title'],
                    'summary' => $row['summary'],
                    'body' => null,
                    'category' => $row['category'],
                    'starts_at' => $row['starts_at'],
                    'ends_at' => null,
                    'location' => $row['location'],
                    'is_featured' => $row['is_featured'],
                    'status' => EventStatus::Published,
                    'published_at' => now(),
                ],
            );
        }
    }
}
