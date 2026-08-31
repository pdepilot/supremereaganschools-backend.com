<?php

namespace App\Support;

use App\Models\SchoolSetting;

class SchoolIdentity
{
    public static function settings(): ?SchoolSetting
    {
        return SchoolSetting::query()->first();
    }

    public static function name(): string
    {
        $name = trim((string) (static::settings()?->name ?: ''));

        return $name !== '' ? $name : 'Supreme Reagan Schools';
    }

    public static function motto(): string
    {
        $motto = trim((string) (static::settings()?->motto ?: ''));

        return $motto !== '' ? $motto : 'Knowledge · Character · Excellence';
    }

    public static function addressHtml(): string
    {
        $settings = static::settings();

        if ($settings && filled($settings->address)) {
            $line = e($settings->address);
            $city = trim(implode(', ', array_filter([
                (string) $settings->city,
                (string) $settings->state,
            ])));

            return $city !== '' ? $line.'<br>'.e($city) : $line;
        }

        return '15 Spibat Road,<br>Amakohia-Akwakuma, Owerri,<br>Imo State, Nigeria.';
    }

    public static function addressText(): string
    {
        return trim(html_entity_decode(strip_tags(str_replace('<br>', "\n", static::addressHtml()))));
    }

    public static function phone(): string
    {
        $phone = trim((string) (static::settings()?->phone ?: ''));

        return $phone !== '' ? $phone : '09065641343';
    }

    public static function email(): string
    {
        $email = trim((string) (static::settings()?->email ?: ''));

        return $email !== '' ? $email : 'supremereagansch@gmail.com';
    }

    public static function logoUrl(): string
    {
        $path = static::settings()?->logo_path;

        return filled($path)
            ? (str_starts_with((string) $path, 'http') ? (string) $path : url((string) $path))
            : url('/site/Image/logo_main.png');
    }

    public static function url(): string
    {
        $website = trim((string) (static::settings()?->website ?: ''));

        return $website !== '' ? $website : url('/');
    }
}
