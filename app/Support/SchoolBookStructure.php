<?php

namespace App\Support;

/**
 * Canonical Supreme Reagan class book used by seeders and CBT admin lookups.
 */
final class SchoolBookStructure
{
    /** @var list<string> */
    public const LEVEL_SLUGS = ['activity', 'nursery', 'primary', 'jss'];

    /**
     * @return array<string, list<array{name: string, short_code: string, arms: list<string>}>>
     */
    public static function classesByLevel(): array
    {
        return [
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
            'jss' => [
                ['name' => 'JSS 1', 'short_code' => 'J1', 'arms' => ['Reagan', 'Diamond']],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function schoolClassNames(): array
    {
        $names = [];
        foreach (self::classesByLevel() as $classes) {
            foreach ($classes as $class) {
                $names[] = $class['name'];
            }
        }

        return $names;
    }

    /**
     * Exact form display names (e.g. "Nursery 2 – Awesome").
     *
     * @return list<string>
     */
    public static function formNames(): array
    {
        $names = [];
        foreach (self::classesByLevel() as $classes) {
            foreach ($classes as $class) {
                foreach ($class['arms'] as $arm) {
                    $names[] = $arm === ''
                        ? $class['name']
                        : $class['name'].' – '.$arm;
                }
            }
        }

        return $names;
    }

    /**
     * Default subject catalogue names for a school-book class.
     *
     * @return list<string>
     */
    public static function defaultSubjectNames(string $className, ?string $levelSlug = null): array
    {
        if (preg_match('/^Nursery\s+2\b/i', $className) === 1) {
            return [
                'Discover Numeracy',
                'Discover Literacy',
                'Discover Me',
                'Numeracy Thinking',
                'Literacy Thinking',
                'Computer',
                'Christian Religious Studies',
                'Igbo',
                'Health Habit',
                'Social Habit',
                'Calligraphy',
                'Colouring',
                'Diction',
                'Literature',
                'Writing',
            ];
        }

        if (preg_match('/^Nursery\s+3\b/i', $className) === 1) {
            return [
                'Discover Numeracy',
                'Discover Literacy',
                'Numeracy Thinking',
                'Literacy Thinking',
                'Calligraphy',
                'Writing',
                'Health Habit',
                'Social Habit',
                'Discovery Science',
                'Computer Science',
                'Christian Religious Studies',
                'Phonics',
                'Literature',
                'Igbo',
                'Diction',
                'Colour Me',
            ];
        }

        if (preg_match('/^Basic\s+[123]\b/i', $className) === 1) {
            return [
                'Mathematics',
                'English',
                'Diction',
                'Abacus',
                'Social Studies',
                'French',
                'Basic Science',
                'Christian Religious Studies',
                'Physical and Health Education',
                'Computer',
                'Home Economics',
                'Vocational Aptitude',
                'Cultural and Creative Arts',
                'Writing',
                'Music',
                'Agricultural Science',
                'Quantitative Reasoning',
                'Verbal Reasoning',
                'Literature',
                'Coding',
                'Civic Education',
                'Igbo',
            ];
        }

        if (preg_match('/^Basic\s+[45]\b/i', $className) === 1) {
            return [
                'Mathematics',
                'English',
                'Diction',
                'Abacus',
                'Social Studies',
                'French',
                'Basic Science',
                'Christian Religious Studies',
                'Physical and Health Education',
                'Computer',
                'Home Economics',
                'Cultural and Creative Arts',
                'Music',
                'Agricultural Science',
                'Quantitative Reasoning',
                'Verbal Reasoning',
                'Literature',
                'Coding',
                'Civic Education',
                'Igbo',
            ];
        }

        if (preg_match('/^JSS\s+1\b/i', $className) === 1 || $levelSlug === 'jss') {
            return [
                'Mathematics',
                'English Studies',
                'Intermediate Science',
                'Digital Technology',
                'Physical and Health Education',
                'Social and Citizenship Studies',
                'Solar PV',
                'Fashion Design',
                'Business Studies',
                'Cultural and Creative Arts',
                'Nigerian History',
                'Igbo',
                'Cambridge Science',
                'French',
                'Christian Religious Studies',
                'Coding and Robotics',
            ];
        }

        return match ($levelSlug) {
            'activity' => ['English', 'Mathematics', 'Quantitative Reasoning', 'Writing', 'Music'],
            'nursery' => ['English', 'Mathematics', 'Quantitative Reasoning', 'Writing', 'Music', 'Diction'],
            default => [],
        };
    }
}
