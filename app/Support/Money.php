<?php

namespace App\Support;

class Money
{
    public static function toKobo(mixed $naira): int
    {
        return (int) round(((float) $naira) * 100);
    }

    public static function toNaira(int $kobo): float
    {
        return round($kobo / 100, 2);
    }

    public static function formatNaira(int $kobo): string
    {
        $naira = self::toNaira($kobo);
        $formatted = abs($naira - round($naira)) < 0.001
            ? number_format((int) round($naira))
            : number_format($naira, 2);

        return '₦'.$formatted;
    }

    /**
     * @return array{count: float|int, prefix: string, suffix: string, label: string}
     */
    public static function compactNaira(int $kobo): array
    {
        $naira = self::toNaira($kobo);

        if ($naira >= 1_000_000) {
            $count = round($naira / 1_000_000, 1);

            return [
                'count' => $count,
                'prefix' => '₦',
                'suffix' => 'M',
                'label' => '₦'.$count.'M',
            ];
        }

        if ($naira >= 10_000) {
            $count = round($naira / 1_000, 1);

            return [
                'count' => $count,
                'prefix' => '₦',
                'suffix' => 'k',
                'label' => '₦'.$count.'k',
            ];
        }

        return [
            'count' => (int) round($naira),
            'prefix' => '₦',
            'suffix' => '',
            'label' => self::formatNaira($kobo),
        ];
    }
}
