<?php

namespace App\Enums;

enum CbtSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Conflict = 'conflict';
}
