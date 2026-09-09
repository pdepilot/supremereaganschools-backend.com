<?php

namespace App\Enums;

enum CbtSubmissionReason: string
{
    case StudentManual = 'student_manual';
    case TimerExpired = 'timer_expired';
    case AutoSubmittedExamExit = 'auto_submitted_exam_exit';
    case System = 'system';
}
