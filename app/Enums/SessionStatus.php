<?php

namespace App\Enums;

enum SessionStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Archived = 'archived';
}
