<?php

namespace Database\Seeders;

use App\Enums\EnquiryStatus;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Models\AdmissionApplication;
use App\Models\ContactEnquiry;
use App\Services\ApplicationService;
use App\Services\EnquiryService;
use Illuminate\Database\Seeder;

class AdmissionsSeeder extends Seeder
{
    public function run(): void
    {
        $enquiries = app(EnquiryService::class);

        if (! ContactEnquiry::query()->where('email', 'ngozi.visit@example.test')->exists()) {
            $enquiries->submit([
                'name' => 'Mrs. Ngozi Eze',
                'phone' => '08031110011',
                'email' => 'ngozi.visit@example.test',
                'subject' => 'Request a campus visit',
                'message' => 'Campus visit for her son before first-term admissions close.',
            ]);
        }

        if (! ContactEnquiry::query()->where('email', 'pta@example.test')->exists()) {
            ContactEnquiry::query()->create([
                'name' => 'PTA Secretariat',
                'phone' => '08030000000',
                'email' => 'pta@example.test',
                'subject' => 'General correspondence',
                'message' => 'Confirming the hall for Thursday’s briefing.',
                'status' => EnquiryStatus::Unread,
            ]);
        }

        if (AdmissionApplication::query()->where('parent_email', 'ngozi.visit@example.test')->exists()) {
            return;
        }

        app(ApplicationService::class)->submit([
            'session_name' => '2025/2026',
            'level' => 'Secondary',
            'class_applied' => 'JSS 1',
            'entry_term' => 'First Term',
            'surname' => 'Eze',
            'first_name' => 'Ifeanyi',
            'other_names' => null,
            'gender' => Gender::Male,
            'date_of_birth' => '2014-03-12',
            'nationality' => 'Nigerian',
            'state_of_origin' => 'Imo',
            'lga' => 'Owerri North',
            'home_address' => 'Amakohia-Akwakuma, Owerri',
            'previous_school' => null,
            'last_class' => 'Primary 6',
            'parent_name' => 'Mrs. Ngozi Eze',
            'relationship' => GuardianRelationship::Mother,
            'parent_phone' => '08031110011',
            'parent_email' => 'ngozi.visit@example.test',
            'parent_occupation' => 'Trader',
        ]);
    }
}
