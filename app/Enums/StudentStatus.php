<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';
    case Graduated = 'graduated';
    case Withdrawn = 'withdrawn';
}
