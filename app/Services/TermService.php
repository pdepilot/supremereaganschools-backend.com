<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\SchoolSetting;
use App\Models\Term;
use Illuminate\Validation\ValidationException;

class TermService
{
    public function __construct(private readonly AcademicSessionService $sessions) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(AcademicSession $session, array $attributes): Term
    {
        $this->assertDates($session, $attributes['starts_on'] ?? null, $attributes['ends_on'] ?? null);

        return $session->terms()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Term $term, array $attributes): Term
    {
        $session = $term->academicSession;
        $startsOn = $attributes['starts_on'] ?? $term->starts_on?->toDateString();
        $endsOn = $attributes['ends_on'] ?? $term->ends_on?->toDateString();
        $this->assertDates($session, $startsOn, $endsOn);

        $status = $attributes['status'] ?? null;
        $statusEnum = $status instanceof SessionStatus
            ? $status
            : (is_string($status) ? SessionStatus::tryFrom($status) : null);

        if ($statusEnum === SessionStatus::Active) {
            Term::query()
                ->where('academic_session_id', $term->academic_session_id)
                ->where('id', '!=', $term->id)
                ->where('status', SessionStatus::Active)
                ->update(['status' => SessionStatus::Planned]);
        }

        $term->update($attributes);

        return $term->fresh('academicSession');
    }

    public function activate(Term $term): Term
    {
        $updated = $this->update($term, ['status' => SessionStatus::Active->value]);
        $session = $updated->academicSession;

        if ($session && $session->status !== SessionStatus::Active) {
            $this->sessions->activate($session);
            $updated = $updated->fresh('academicSession');
            $session = $updated->academicSession;
        }

        $settings = SchoolSetting::query()->first();

        if ($settings) {
            $settings->update([
                'current_academic_session_id' => $updated->academic_session_id,
                'current_term_id' => $updated->id,
            ]);
        }

        return $updated->fresh('academicSession');
    }

    public function delete(Term $term): void
    {
        $settings = SchoolSetting::query()->first();

        if ($settings?->current_term_id === $term->id) {
            throw ValidationException::withMessages([
                'term' => 'The current term cannot be deleted.',
            ]);
        }

        $term->delete();
    }

    private function assertDates(AcademicSession $session, mixed $startsOn, mixed $endsOn): void
    {
        if ($startsOn === null && $endsOn === null) {
            return;
        }

        if ($startsOn !== null && $endsOn !== null && strtotime((string) $endsOn) < strtotime((string) $startsOn)) {
            throw ValidationException::withMessages([
                'ends_on' => 'The term end date cannot precede the start date.',
            ]);
        }

        if ($startsOn !== null && strtotime((string) $startsOn) < $session->starts_on->startOfDay()->timestamp) {
            throw ValidationException::withMessages([
                'starts_on' => 'The term must begin on or after the session start date.',
            ]);
        }

        if ($endsOn !== null && strtotime((string) $endsOn) > $session->ends_on->endOfDay()->timestamp) {
            throw ValidationException::withMessages([
                'ends_on' => 'The term must end on or before the session end date.',
            ]);
        }
    }
}
