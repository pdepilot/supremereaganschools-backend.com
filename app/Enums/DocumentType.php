<?php

namespace App\Enums;

enum DocumentType: string
{
    case PassportPhoto = 'passport_photo';
    case BirthCertificate = 'birth_certificate';
    case ExamReceipt = 'exam_receipt';
    case LearningMaterial = 'learning_material';
    case AssignmentSubmission = 'assignment_submission';
    case Other = 'other';
}
