<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\SchoolSetting;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Carbon;

class ParentDeskService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        $user->loadMissing('guardianProfile');
        $now = Carbon::now('Africa/Lagos');
        $settings = SchoolSetting::query()->with(['currentAcademicSession', 'currentTerm'])->first();
        $guardian = $user->guardianProfile;
        $fullName = $guardian?->full_name ?: (trim($user->name) !== '' ? trim($user->name) : 'Parent');
        $firstName = explode(' ', $fullName)[0] ?? 'Parent';

        $children = $guardian === null
            ? collect()
            : $guardian->students()
                ->wherePivot('can_login', true)
                ->with([
                    'enrollments' => fn ($enrollment) => $enrollment
                        ->where('status', EnrollmentStatus::Active)
                        ->with([
                            'classSectionOffering.classSection',
                            'classSectionOffering.campus',
                        ]),
                ])
                ->orderBy('surname')
                ->orderBy('first_name')
                ->get();

        return [
            'id' => $guardian?->id,
            'name' => $firstName,
            'full_name' => $fullName,
            'initials' => $this->initials($fullName),
            'school' => $settings?->name ?: (string) config('app.name'),
            'school_short' => $this->brandName($settings),
            'logo_path' => $settings?->logo_path ?: '/site/Image/logo_main.png',
            'session' => $settings?->currentAcademicSession?->name,
            'term' => $settings?->currentTerm?->name,
            'date_label' => $now->format('l, j F'),
            'drawn_at' => $now->toIso8601String(),
            'metrics' => [
                'children' => $children->count(),
            ],
            'children' => $children->map(fn (StudentProfile $child) => $this->childRow($child))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function childRow(StudentProfile $child): array
    {
        $current = $child->enrollments->first(
            fn (Enrollment $enrollment) => $enrollment->status === EnrollmentStatus::Active,
        ) ?? $child->enrollments->first();
        $offering = $current?->classSectionOffering;

        return [
            'id' => $child->id,
            'full_name' => $child->fullName(),
            'initials' => $this->initials($child->fullName()),
            'admission_number' => $child->admission_number,
            'photo_url' => filled($child->photo_path) ? '/api/v1/students/'.$child->id.'/photo' : null,
            'form' => $offering?->classSection?->name,
            'campus' => $offering?->campus?->name,
            'class_section_offering_id' => $current?->class_section_offering_id,
            'status' => $child->status?->value,
            'relationship' => is_object($child->pivot?->relationship)
                ? $child->pivot->relationship->value
                : $child->pivot?->relationship,
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return strtoupper(collect($parts)->filter()->take(2)->map(
            fn (string $part) => mb_substr($part, 0, 1),
        )->implode('')) ?: 'P';
    }

    private function brandName(?SchoolSetting $settings): string
    {
        $name = trim((string) ($settings?->name ?: 'Supreme Reagan Schools'));
        $name = preg_replace('/\s+Schools?$/i', '', $name) ?? $name;

        return $name !== '' ? $name : 'Supreme Reagan';
    }
}
