<?php

namespace App\Support;

class Phone
{
    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Compare Nigerian (or other) phone numbers ignoring spaces, zeros, and country code.
     */
    public static function matches(string $attempt, string $stored): bool
    {
        $left = self::nationalKey($attempt);
        $right = self::nationalKey($stored);

        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right || self::digits($attempt) === self::digits($stored);
    }

    public static function nationalKey(string $value): string
    {
        $digits = self::digits($value);

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '234') && strlen($digits) >= 13) {
            $digits = substr($digits, 3);
        }

        if (str_starts_with($digits, '0') && strlen($digits) >= 11) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }
}
