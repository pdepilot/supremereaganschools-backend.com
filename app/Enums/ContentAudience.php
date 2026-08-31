<?php

namespace App\Enums;

enum ContentAudience: string
{
    case Parents = 'parents';
    case Students = 'students';
    case Teachers = 'teachers';
    case General = 'general';
}
