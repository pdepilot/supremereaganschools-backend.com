<?php

namespace App\Enums;

enum OutboundMailStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case Partial = 'partial';
}
