<?php

namespace App\Enums;

enum EnquiryStatus: string
{
    case Unread = 'unread';
    case Read = 'read';
    case Urgent = 'urgent';
    case Cleared = 'cleared';
}
