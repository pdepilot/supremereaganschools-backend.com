<?php

namespace Database\Seeders;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\ClassSection;
use App\Models\ClassSectionOffering;
use App\Models\Conversation;
use App\Models\LearningMaterial;
use App\Models\StaffProfile;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\AssignmentService;
use App\Services\MaterialService;
use App\Services\MessagingService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ClassroomSeeder extends Seeder
{
    public function run(): void
    {
        $admin = LocalAdminSeeder::user();
        $eze = User::query()->where('email', 'eze@supremereaganschools.test')->first();
        $parent = User::query()->where('email', 'okafor@supremereaganschools.test')->first();

        if ($admin === null || $eze === null) {
            return;
        }

        $eze->load('staffProfile');

        $announcements = app(AnnouncementService::class);

        if (! Announcement::query()->where('title', 'Mid-term examinations')->exists()) {
            $announcements->create([
                'title' => 'Mid-term examinations',
                'body' => 'Week of 15 September · all secondary forms.',
                'category' => AnnouncementCategory::Academic->value,
                'audience' => AnnouncementAudience::WholeSchool->value,
                'status' => AnnouncementStatus::Published->value,
            ], $admin);
        }

        if (! Announcement::query()->where('title', 'PTA briefing')->exists()) {
            $announcements->create([
                'title' => 'PTA briefing',
                'body' => 'Thursday · school hall · 2:00 p.m.',
                'category' => AnnouncementCategory::Event->value,
                'audience' => AnnouncementAudience::Parents->value,
                'status' => AnnouncementStatus::Published->value,
            ], $admin);
        }

        $offering = $this->offeringNamed('JSS 2 A');
        $math = Subject::query()->where('name', 'Mathematics')->first();
        $science = Subject::query()->where('name', 'Basic Science')->first();
        $ezeStaff = $eze->staffProfile;
        $okoroStaff = StaffProfile::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'dokoro@supremereaganschools.test'))
            ->first();

        $term = Term::query()
            ->where('name', 'First Term')
            ->whereHas('academicSession', fn ($query) => $query->where('name', '2025/2026'))
            ->first();

        if ($offering && $ezeStaff && ! TimetableSlot::query()->where('class_section_offering_id', $offering->id)->exists()) {
            $this->slot($offering, $term, 1, '08:00', '08:40', $math, $ezeStaff);
            $this->slot($offering, $term, 1, '08:40', '09:20', $science, $okoroStaff);
            $this->slot($offering, $term, 1, '09:20', '10:00', $math, $ezeStaff);
            $this->slot($offering, $term, 1, '10:40', '11:00', null, null, 'Break');
            $this->slot($offering, $term, 1, '11:00', '11:40', $science, $okoroStaff);

            $this->slot($offering, $term, 2, '08:00', '08:40', $science, $okoroStaff);
            $this->slot($offering, $term, 2, '08:40', '09:20', $math, $ezeStaff);
            $this->slot($offering, $term, 2, '10:40', '11:00', null, null, 'Break');
            $this->slot($offering, $term, 2, '11:00', '11:40', $math, $ezeStaff);

            $this->slot($offering, $term, 3, '08:00', '08:40', $math, $ezeStaff);
            $this->slot($offering, $term, 3, '10:40', '11:00', null, null, 'Break');

            $this->slot($offering, $term, 4, '08:00', '08:40', $science, $okoroStaff);
            $this->slot($offering, $term, 4, '10:40', '11:00', null, null, 'Break');
            $this->slot($offering, $term, 4, '11:00', '11:40', $math, $ezeStaff);

            $this->slot($offering, $term, 5, '08:00', '08:40', $math, $ezeStaff);
            $this->slot($offering, $term, 5, '08:40', '09:20', $science, $okoroStaff);
            $this->slot($offering, $term, 5, '10:40', '11:00', null, null, 'Break');
        }

        if ($offering && $math && $ezeStaff && ! Assignment::query()->where('title', 'Exercise 4 — Linear equations')->exists()) {
            app(AssignmentService::class)->create([
                'class_section_offering_id' => $offering->id,
                'subject_id' => $math->id,
                'title' => 'Exercise 4 — Linear equations',
                'instructions' => 'Complete page 12. Show working.',
                'due_on' => '2026-09-10',
            ], $eze);
        }

        if ($offering && $math && ! LearningMaterial::query()->where('title', 'Algebra week 3 notes')->exists()) {
            Storage::disk('local')->makeDirectory('documents/learning_material');
            $file = UploadedFile::fake()->create('algebra-week-3.pdf', 40, 'application/pdf');
            app(MaterialService::class)->create([
                'class_section_offering_id' => $offering->id,
                'subject_id' => $math->id,
                'title' => 'Algebra week 3 notes',
            ], $file, $eze);
        }

        if ($parent && ! Conversation::query()->where('subject', 'Mathematics homework')->exists()) {
            app(MessagingService::class)->start([
                'recipient_id' => $parent->id,
                'subject' => 'Mathematics homework',
                'body' => 'Please remind Chiamaka to submit Exercise 4 tomorrow.',
            ], $eze);
        }
    }

    private function offeringNamed(string $form): ?ClassSectionOffering
    {
        $section = ClassSection::query()->where('name', $form)->first();

        if ($section === null) {
            return null;
        }

        return ClassSectionOffering::query()
            ->where('class_section_id', $section->id)
            ->orderByDesc('id')
            ->first();
    }

    private function slot(
        ClassSectionOffering $offering,
        ?Term $term,
        int $day,
        string $start,
        string $end,
        ?Subject $subject,
        ?StaffProfile $staff,
        ?string $label = null,
    ): void {
        TimetableSlot::query()->create([
            'class_section_offering_id' => $offering->id,
            'term_id' => $term?->id,
            'day_of_week' => $day,
            'starts_at' => $start,
            'ends_at' => $end,
            'subject_id' => $subject?->id,
            'staff_profile_id' => $staff?->id,
            'label' => $label,
        ]);
    }
}
