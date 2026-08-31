<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Transferred = 'transferred';
    case Withdrawn = 'withdrawn';
}
