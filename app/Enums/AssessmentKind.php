<?php

namespace App\Enums;

enum AssessmentKind: string
{
    case FirstCa = 'first_ca';
    case SecondCa = 'second_ca';
    case Examination = 'examination';
}
