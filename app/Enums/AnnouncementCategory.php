<?php

namespace App\Enums;

enum AnnouncementCategory: string
{
    case Academic = 'academic';
    case Event = 'event';
    case General = 'general';
    case Urgent = 'urgent';
}
