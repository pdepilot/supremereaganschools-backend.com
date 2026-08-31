<?php

namespace App\Enums;

enum CtaDestination: string
{
    case None = 'none';
    case About = 'about';
    case Academics = 'academics';
    case Admissions = 'admissions';
    case Contact = 'contact';
    case StudentLife = 'student-life';
    case ParentResources = 'parent-resources';
}
