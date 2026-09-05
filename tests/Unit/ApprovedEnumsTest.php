<?php

namespace Tests\Unit;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\ApplicationStatus;
use App\Enums\AssessmentKind;
use App\Enums\AttendanceStatus;
use App\Enums\DocumentType;
use App\Enums\EnrollmentStatus;
use App\Enums\EnquiryStatus;
use App\Enums\FeeChannel;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use App\Enums\StaffStatus;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use PHPUnit\Framework\TestCase;

class ApprovedEnumsTest extends TestCase
{
    public function test_approved_enum_values_match_the_architecture(): void
    {
        $this->assertSame(['active', 'inactive', 'suspended'], $this->values(UserStatus::class));
        $this->assertSame([
            'super_admin',
            'school_admin',
            'principal',
            'vice_principal',
            'examination_officer',
            'admissions_officer',
            'content_manager',
            'teacher',
            'accountant',
            'staff',
            'parent',
            'student',
        ], $this->values(RoleSlug::class));
        $this->assertSame(['male', 'female'], $this->values(Gender::class));
        $this->assertSame(['father', 'mother', 'guardian'], $this->values(GuardianRelationship::class));
        $this->assertSame(['pending', 'active', 'inactive', 'graduated', 'withdrawn'], $this->values(StudentStatus::class));
        $this->assertSame(['active', 'on_leave', 'inactive'], $this->values(StaffStatus::class));
        $this->assertSame(['active', 'completed', 'transferred', 'withdrawn'], $this->values(EnrollmentStatus::class));
        $this->assertSame(['planned', 'active', 'archived'], $this->values(SessionStatus::class));
        $this->assertSame(['present', 'absent', 'late'], $this->values(AttendanceStatus::class));
        $this->assertSame(['first_ca', 'second_ca', 'examination'], $this->values(AssessmentKind::class));
        $this->assertSame([
            'whole_school',
            'parents',
            'staff',
            'students',
            'secondary',
            'teaching_staff',
            'non_teaching_staff',
            'department',
        ], $this->values(AnnouncementAudience::class));
        $this->assertSame(['academic', 'event', 'general', 'urgent'], $this->values(AnnouncementCategory::class));
        $this->assertSame(['draft', 'published', 'archived'], $this->values(AnnouncementStatus::class));
        $this->assertSame(['cash', 'transfer', 'pos', 'other'], $this->values(FeeChannel::class));
        $this->assertSame(['unpaid', 'partial', 'paid', 'void'], $this->values(InvoiceStatus::class));
        $this->assertSame(['posted', 'pending', 'failed', 'void'], $this->values(PaymentStatus::class));
        $this->assertSame([
            'submitted',
            'under_review',
            'exam_scheduled',
            'offered',
            'admitted',
            'rejected',
            'withdrawn',
        ], $this->values(ApplicationStatus::class));
        $this->assertSame(['unread', 'read', 'urgent', 'cleared'], $this->values(EnquiryStatus::class));
        $this->assertSame([
            'passport_photo',
            'birth_certificate',
            'exam_receipt',
            'learning_material',
            'assignment_submission',
            'other',
        ], $this->values(DocumentType::class));
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @return list<string>
     */
    private function values(string $enum): array
    {
        return array_column($enum::cases(), 'value');
    }
}
