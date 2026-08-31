<?php

namespace App\Enums;

enum AnnouncementAudience: string
{
    case WholeSchool = 'whole_school';
    case Parents = 'parents';
    case Staff = 'staff';
    case Students = 'students';
    case Secondary = 'secondary';
    case TeachingStaff = 'teaching_staff';
    case NonTeachingStaff = 'non_teaching_staff';
    case Department = 'department';
}
