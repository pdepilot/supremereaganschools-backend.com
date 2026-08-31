<?php

namespace App\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';
}
