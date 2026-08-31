<?php

namespace App\Enums;

enum SchoolWing: string
{
    case Nursery = 'nursery';
    case Primary = 'primary';
    case Secondary = 'secondary';

    public function label(): string
    {
        return match ($this) {
            self::Nursery => 'Nursery',
            self::Primary => 'Primary',
            self::Secondary => 'Secondary',
        };
    }

    public function copy(): string
    {
        return match ($this) {
            self::Nursery => 'Early years sealed into the nursery roll',
            self::Primary => 'Primary forms on the school books',
            self::Secondary => 'Junior and senior secondary on one desk',
        };
    }

    /**
     * @return list<string>
     */
    public function levelSlugs(): array
    {
        return match ($this) {
            self::Nursery => ['nursery'],
            self::Primary => ['primary'],
            self::Secondary => ['jss', 'ss'],
        };
    }

    public static function fromLevelSlug(?string $slug): ?self
    {
        return match ($slug) {
            'nursery' => self::Nursery,
            'primary' => self::Primary,
            'jss', 'ss' => self::Secondary,
            default => null,
        };
    }
}
