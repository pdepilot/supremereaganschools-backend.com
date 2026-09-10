<?php

namespace App\Support;

/**
 * Canonical Supreme Reagan class book used by seeders and CBT admin lookups.
 */
final class SchoolBookStructure
{
    /** @var list<string> */
    public const LEVEL_SLUGS = ['activity', 'nursery', 'primary'];

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
}
