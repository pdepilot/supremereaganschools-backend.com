<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $markerId = User::query()->where('email', 'eze@supremereaganschools.test')->value('id')
            ?? LocalAdminSeeder::user()?->id;

        $this->mark('SRS/2025/0142', [
            ['marked_on' => '2025-09-10', 'status' => AttendanceStatus::Present],
            ['marked_on' => '2025-09-11', 'status' => AttendanceStatus::Absent, 'remark' => 'Unwell'],
            ['marked_on' => '2025-09-12', 'status' => AttendanceStatus::Late, 'remark' => 'Arrived after assembly'],
            ['marked_on' => '2025-09-15', 'status' => AttendanceStatus::Present],
        ], $markerId);

        $this->mark('SRS/2025/0198', [
            ['marked_on' => '2025-09-10', 'status' => AttendanceStatus::Present],
        ], $markerId);
    }

    /**
     * @param  list<array{marked_on: string, status: AttendanceStatus, remark?: string}>  $days
     */
    private function mark(string $admissionNumber, array $days, ?int $markerId): void
    {
        $enrollment = StudentProfile::query()
            ->where('admission_number', $admissionNumber)
            ->first()
            ?->enrollments()
            ->orderByDesc('enrolled_on')
            ->first();

        if ($enrollment === null) {
            return;
        }

        foreach ($days as $day) {
            $exists = AttendanceRecord::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereDate('marked_on', $day['marked_on'])
                ->exists();

            if ($exists) {
                continue;
            }

            AttendanceRecord::query()->create([
                'enrollment_id' => $enrollment->id,
                'class_section_offering_id' => $enrollment->class_section_offering_id,
                'marked_on' => $day['marked_on'],
                'status' => $day['status'],
                'remark' => $day['remark'] ?? null,
                'marked_by' => $markerId,
            ]);
        }
    }
}
