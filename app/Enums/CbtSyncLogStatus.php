<?php

namespace App\Enums;

enum CbtSyncLogStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
}
