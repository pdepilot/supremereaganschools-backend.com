<?php

namespace App\Enums;

enum CbtExamStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
