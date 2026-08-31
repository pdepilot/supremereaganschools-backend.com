<?php

namespace App\Enums;

enum FeeChannel: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Pos = 'pos';
    case Other = 'other';
}
