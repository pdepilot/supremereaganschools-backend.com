<?php

namespace App\Enums;

enum ContentIntent: string
{
    case Informational = 'informational';
    case Educational = 'educational';
    case Admissions = 'admissions';
    case SchoolInformation = 'school_information';
}
