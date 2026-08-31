<?php

namespace App\Enums;

enum EmailAudience: string
{
    case User = 'user';
    case Users = 'users';
    case Custom = 'custom';
    case WholeSchool = 'whole_school';
    case Parents = 'parents';
    case Staff = 'staff';
    case Students = 'students';
    case TeachingStaff = 'teaching_staff';
}
