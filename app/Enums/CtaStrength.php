<?php

namespace App\Enums;

enum CtaStrength: string
{
    case None = 'none';
    case Soft = 'soft';
    case Standard = 'standard';
    case Strong = 'strong';
}
