<?php

namespace App\Services;

use App\Models\ClassSectionOffering;
use App\Models\SubjectOffering;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class TimetableService
{
    public function __construct(private readonly PeopleAccessService $access) {}

    /**
     * @return array<string, mixed>
     */
    public function grid(int $offeringId, User $actor, ?int $termId = null, ?int $subjectId = null): array
    {
        if (! $this->access->canViewClassroomOffering($actor, $offeringId)) {
            throw new AuthorizationException;
        }

        $offering = ClassSectionOffering::query()
            ->with(['classSection', 'academicSession', 'activeClassTeacher.staff.user'])
            ->findOrFail($offeringId);

        $query = TimetableSlot::query()
            ->with(['subject', 'staff.user'])
            ->where('class_section_offering_id', $offeringId)
            ->orderBy('day_of_week')
            ->orderBy('starts_at');

        if ($termId) {
            $query->where(function ($inner) use ($termId) {
                $inner->where('term_id', $termId)->orWhereNull('term_id');
            });
        }
        if ($subjectId) {
            $query->where('subject_id', $subjectId);
        }

        $slots = $query->get();
        $starts = $slots->map(fn (TimetableSlot $slot) => substr((string) $slot->starts_at, 0, 5))->unique()->values();
        $ends = $slots->map(fn (TimetableSlot $slot) => substr((string) $slot->ends_at, 0, 5));

        return [
            'class_section_offering_id' => $offering->id,
            'form' => $offering->classSection?->name,
            'session_name' => $offering->academicSession?->name,
            'class_teacher' => $offering->activeClassTeacher->first()?->staff?->user?->name,
            'can_edit' => $this->access->administers($actor),
            'period_count' => $starts->count(),
            'first_bell' => $starts->sort()->first(),
            'last_bell' => $ends->sort()->last(),
            'mapped_forms' => TimetableSlot::query()->pluck('class_section_offering_id')->unique()->count(),
            'slots' => $slots->map(fn (TimetableSlot $slot) => $this->payload($slot))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): TimetableSlot
    {
        $this->assertAdmin($actor);
        $this->assertTimes($attributes['starts_at'], $attributes['ends_at']);
        $this->assertSubjectOffered($attributes);
        $this->assertSlotFree($attributes);

        return TimetableSlot::query()->create($attributes)->load(['subject', 'staff.user', 'classSectionOffering.classSection']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(TimetableSlot $slot, array $attributes, User $actor): TimetableSlot
    {
        $this->assertAdmin($actor);
        if (isset($attributes['starts_at'], $attributes['ends_at'])) {
            $this->assertTimes($attributes['starts_at'], $attributes['ends_at']);
        }
        $this->assertSubjectOffered(array_merge($slot->only(['class_section_offering_id', 'subject_id']), $attributes));
        $this->assertSlotFree(array_merge($slot->only(['class_section_offering_id', 'day_of_week', 'starts_at']), $attributes), $slot->id);

        $slot->update($attributes);

        return $slot->fresh(['subject', 'staff.user', 'classSectionOffering.classSection']);
    }

    public function delete(TimetableSlot $slot, User $actor): void
    {
        $this->assertAdmin($actor);
        $slot->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(TimetableSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'class_section_offering_id' => $slot->class_section_offering_id,
            'term_id' => $slot->term_id,
            'day_of_week' => $slot->day_of_week,
            'starts_at' => substr((string) $slot->starts_at, 0, 5),
            'ends_at' => substr((string) $slot->ends_at, 0, 5),
            'subject_id' => $slot->subject_id,
            'subject_name' => $slot->subject?->name,
            'staff_profile_id' => $slot->staff_profile_id,
            'staff_name' => $slot->staff?->user?->name,
            'label' => $slot->label,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertSlotFree(array $attributes, ?int $ignoreId = null): void
    {
        $taken = TimetableSlot::query()
            ->where('class_section_offering_id', $attributes['class_section_offering_id'])
            ->where('day_of_week', $attributes['day_of_week'])
            ->where('starts_at', $attributes['starts_at'])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'starts_at' => 'A lesson already occupies this time for the class.',
            ]);
        }
    }

    private function assertAdmin(User $actor): void
    {
        if (! $this->access->administers($actor)) {
            throw new AuthorizationException;
        }
    }

    private function assertTimes(string $start, string $end): void
    {
        if ($end <= $start) {
            throw ValidationException::withMessages([
                'ends_at' => 'The lesson must end after it starts.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertSubjectOffered(array $attributes): void
    {
        if (empty($attributes['subject_id']) || empty($attributes['class_section_offering_id'])) {
            return;
        }

        $exists = SubjectOffering::query()
            ->where('class_section_offering_id', $attributes['class_section_offering_id'])
            ->where('subject_id', $attributes['subject_id'])
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'subject_id' => 'This subject is not offered in the selected class.',
            ]);
        }
    }
}
