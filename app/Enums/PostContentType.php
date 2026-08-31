<?php

namespace App\Enums;

enum PostContentType: string
{
    case Article = 'article';
    case Guide = 'guide';
    case Resource = 'resource';
    case Announcement = 'announcement';
    case Event = 'event';
    case AdmissionUpdate = 'admission_update';

    public function label(): string
    {
        return match ($this) {
            self::Article => 'Article',
            self::Guide => 'Guide',
            self::Resource => 'Resource',
            self::Announcement => 'Announcement',
            self::Event => 'Event',
            self::AdmissionUpdate => 'Admission update',
        };
    }
}
