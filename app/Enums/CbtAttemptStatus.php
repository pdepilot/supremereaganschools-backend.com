<?php

namespace App\Enums;

enum CbtAttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Abandoned = 'abandoned';
    case Void = 'void';
}
