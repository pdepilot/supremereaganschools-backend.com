<?php

namespace App\Enums;

enum EducationalLevel: string
{
    case EarlyYears = 'early_years';
    case Primary = 'primary';
    case JuniorSecondary = 'junior_secondary';
    case SeniorSecondary = 'senior_secondary';
    case All = 'all';
}
