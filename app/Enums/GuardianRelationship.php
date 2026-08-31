<?php

namespace App\Enums;

enum GuardianRelationship: string
{
    case Father = 'father';
    case Mother = 'mother';
    case Guardian = 'guardian';
}
