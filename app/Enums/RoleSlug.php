<?php

namespace App\Enums;

enum RoleSlug: string
{
    case SuperAdmin = 'super_admin';
    case SchoolAdmin = 'school_admin';
    case Principal = 'principal';
    case VicePrincipal = 'vice_principal';
    case Teacher = 'teacher';
    case Accountant = 'accountant';
    case Staff = 'staff';
    case Parent = 'parent';
    case Student = 'student';
}
