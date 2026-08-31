<?php

namespace Database\Seeders;

use App\Models\PostCategory;
use App\Models\PostTag;
use App\Models\PublishingSetting;
use App\Models\ResourceHub;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class NewsInsightsSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'School News',
            'Education',
            'Parenting',
            'Student Life',
            'Academic Resources',
            'Admissions',
            'Events',
            'School Community',
            'Student Development',
            'Examinations',
        ];

        foreach ($categories as $index => $name) {
            PostCategory::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => 'Writing from Supreme Reagan Schools on '.$name.'.',
                    'meta_title' => $name.' | Supreme Reagan Schools',
                    'meta_description' => 'Guidance and notices from Supreme Reagan Schools — '.$name.'.',
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }

        $tags = [
            'WAEC', 'NECO', 'Secondary Education', 'Primary Education', 'Study Tips',
            'Parenting', 'Examination', 'School Life', 'Leadership', 'Technology',
            'Sports', 'Mathematics', 'Science', 'Admissions',
        ];

        foreach ($tags as $name) {
            PostTag::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => $name.' at Supreme Reagan Schools.',
                    'meta_title' => $name.' | Supreme Reagan Schools',
                    'meta_description' => 'Articles tagged '.$name.' from the house.',
                ],
            );
        }

        PublishingSetting::current();

        $this->seedHubs();
    }

    private function seedHubs(): void
    {
        $hubs = [
            [
                'name' => 'Parent Resources',
                'slug' => 'parenting',
                'kicker' => 'For families',
                'intro' => "Notes for parents who are helping a child study, grow, and choose a school.\n\nThis hub is for real household questions: homework, reading, examinations, discipline, and the conversations that sit around a school year. Supreme Reagan Schools appears only where it is useful — after the guidance, not instead of it.",
                'meta_title' => 'Parent Resources',
                'meta_description' => 'Parenting and education guidance from Supreme Reagan Schools — study, school decisions, and the life of the child.',
                'cta_type' => 'parent-resources',
                'is_parent_hub' => true,
                'sort_order' => 1,
                'categories' => ['parenting', 'admissions'],
            ],
            [
                'name' => 'Study & Academic Success',
                'slug' => 'study-tips',
                'kicker' => 'Study',
                'intro' => "How to work: habits, time, reading, mathematics, science, and English.\n\nThese pages are for pupils and the adults who sit with them. They are not a promise of scores. They are the ordinary methods the house respects.",
                'meta_title' => 'Study Tips',
                'meta_description' => 'Study techniques, revision, and academic habits from Supreme Reagan Schools.',
                'cta_type' => 'academics',
                'sort_order' => 2,
                'categories' => ['education', 'academic-resources'],
            ],
            [
                'name' => 'Examination Preparation',
                'slug' => 'examination-preparation',
                'kicker' => 'Examinations',
                'intro' => "WAEC, NECO, and the common work of preparing without panic.\n\nA plan, a timetable, sleep, and honest revision. The house does not sell fear in examination season.",
                'meta_title' => 'Examination Preparation',
                'meta_description' => 'Examination preparation guidance from Supreme Reagan Schools — revision, time, and calm practice.',
                'cta_type' => 'academics',
                'sort_order' => 3,
                'categories' => ['examinations', 'education', 'academic-resources'],
            ],
            [
                'name' => 'Student Development',
                'slug' => 'student-development',
                'kicker' => 'Character',
                'intro' => "Leadership, confidence, communication, creativity, and the habits that sit beside the lesson.\n\nThe house is not only papers. These notes describe the work of becoming a person who can speak, think, and stand with others.",
                'meta_title' => 'Student Development',
                'meta_description' => 'Student development notes from Supreme Reagan Schools — leadership, character, and future skills.',
                'cta_type' => 'student-life',
                'sort_order' => 4,
                'categories' => ['student-development', 'student-life'],
            ],
        ];

        foreach ($hubs as $payload) {
            $slugs = $payload['categories'];
            unset($payload['categories']);
            $payload['is_active'] = true;
            $hub = ResourceHub::query()->updateOrCreate(['slug' => $payload['slug']], $payload);
            $ids = PostCategory::query()->whereIn('slug', $slugs)->pluck('id');
            $hub->categories()->sync($ids);
        }
    }
}
