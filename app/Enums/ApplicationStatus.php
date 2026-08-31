<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ExamScheduled = 'exam_scheduled';
    case Offered = 'offered';
    case Admitted = 'admitted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
