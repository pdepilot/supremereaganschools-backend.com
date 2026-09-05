<?php

namespace App\Providers;

use App\Enums\RoleSlug;
use App\Models\AcademicSession;
use App\Models\Campus;
use App\Models\ClassSection;
use App\Models\ClassSectionOffering;
use App\Models\Department;
use App\Models\Level;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\ClassTeacherAssignment;
use App\Models\Enrollment;
use App\Models\GuardianProfile;
use App\Models\GuardianStudent;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\SubjectTeacherAssignment;
use App\Models\User;
use App\Policies\AcademicStructurePolicy;
use App\Policies\ClassTeacherAssignmentPolicy;
use App\Policies\EnrollmentPolicy;
use App\Policies\GuardianProfilePolicy;
use App\Policies\GuardianStudentPolicy;
use App\Policies\RolePolicy;
use App\Policies\StaffProfilePolicy;
use App\Policies\StudentProfilePolicy;
use App\Policies\SubjectTeacherAssignmentPolicy;
use App\Models\AdmissionApplication;
use App\Models\Announcement;
use App\Models\AssessmentScore;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\ContactEnquiry;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\EmailTemplate;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\LearningMaterial;
use App\Models\OutboundMail;
use App\Models\Payment;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostTag;
use App\Models\TimetableSlot;
use App\Policies\AdmissionApplicationPolicy;
use App\Policies\AnnouncementPolicy;
use App\Policies\AssessmentPolicy;
use App\Policies\AssignmentPolicy;
use App\Policies\AttendanceRecordPolicy;
use App\Policies\ContactEnquiryPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EmailCenterPolicy;
use App\Policies\FeeStructurePolicy;
use App\Policies\FeeTypePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\LearningMaterialPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PostCategoryPolicy;
use App\Policies\PostPolicy;
use App\Policies\PostTagPolicy;
use App\Policies\TimetableSlotPolicy;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        Gate::before(function ($user, string $ability) {
            if ($user instanceof User && $user->hasRole(RoleSlug::SuperAdmin)) {
                return true;
            }

            return null;
        });

        $policy = AcademicStructurePolicy::class;

        foreach ([
            SchoolSetting::class,
            Campus::class,
            AcademicSession::class,
            Term::class,
            Level::class,
            SchoolClass::class,
            ClassSection::class,
            Department::class,
            Subject::class,
            ClassSectionOffering::class,
            SubjectOffering::class,
        ] as $model) {
            Gate::policy($model, $policy);
        }

        Gate::policy(StaffProfile::class, StaffProfilePolicy::class);
        Gate::policy(StudentProfile::class, StudentProfilePolicy::class);
        Gate::policy(GuardianProfile::class, GuardianProfilePolicy::class);
        Gate::policy(GuardianStudent::class, GuardianStudentPolicy::class);
        Gate::policy(Enrollment::class, EnrollmentPolicy::class);
        Gate::policy(ClassTeacherAssignment::class, ClassTeacherAssignmentPolicy::class);
        Gate::policy(SubjectTeacherAssignment::class, SubjectTeacherAssignmentPolicy::class);
        Gate::policy(AttendanceRecord::class, AttendanceRecordPolicy::class);
        Gate::policy(AssessmentScore::class, AssessmentPolicy::class);
        Gate::policy(FeeType::class, FeeTypePolicy::class);
        Gate::policy(FeeStructure::class, FeeStructurePolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(ContactEnquiry::class, ContactEnquiryPolicy::class);
        Gate::policy(AdmissionApplication::class, AdmissionApplicationPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::policy(TimetableSlot::class, TimetableSlotPolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
        Gate::policy(LearningMaterial::class, LearningMaterialPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(EmailTemplate::class, EmailCenterPolicy::class);
        Gate::policy(OutboundMail::class, EmailCenterPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(PostCategory::class, PostCategoryPolicy::class);
        Gate::policy(PostTag::class, PostTagPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
    }
}
