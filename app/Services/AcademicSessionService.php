<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\SchoolSetting;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicSessionService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?int $createdBy = null): AcademicSession
    {
        $this->assertDates($attributes['starts_on'], $attributes['ends_on']);

        return DB::transaction(function () use ($attributes, $createdBy) {
            $session = AcademicSession::query()->create([
                ...$attributes,
                'created_by' => $createdBy,
            ]);

            $this->seedTerms($session);

            if ($this->statusFrom($session->status) === SessionStatus::Active) {
                $this->activate($session);
            }

            return $session->load('terms');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AcademicSession $session, array $attributes): AcademicSession
    {
        $startsOn = $attributes['starts_on'] ?? $session->starts_on->toDateString();
        $endsOn = $attributes['ends_on'] ?? $session->ends_on->toDateString();
        $this->assertDates($startsOn, $endsOn);

        return DB::transaction(function () use ($session, $attributes) {
            $previous = $session->status;
            $becomingActive = $this->statusFrom($attributes['status'] ?? null) === SessionStatus::Active
                && $previous !== SessionStatus::Active;

            $session->update($attributes);

            if ($becomingActive) {
                $this->activate($session->fresh());
            } elseif ($this->statusFrom($attributes['status'] ?? null) === SessionStatus::Archived
                && $previous !== SessionStatus::Archived) {
                $this->releaseCurrentDesk($session);
            }

            return $session->fresh('terms');
        });
    }

    public function activate(AcademicSession $session): AcademicSession
    {
        return DB::transaction(function () use ($session) {
            $previousIds = AcademicSession::query()
                ->where('id', '!=', $session->id)
                ->where('status', SessionStatus::Active)
                ->pluck('id');

            if ($previousIds->isNotEmpty()) {
                AcademicSession::query()->whereIn('id', $previousIds)->update(['status' => SessionStatus::Archived]);
                Term::query()
                    ->whereIn('academic_session_id', $previousIds)
                    ->where('status', SessionStatus::Active)
                    ->update(['status' => SessionStatus::Planned]);
            }

            $session->update(['status' => SessionStatus::Active]);

            $this->sealOpeningTerm($session->fresh('terms'));

            return $session->fresh('terms');
        });
    }

    private function releaseCurrentDesk(AcademicSession $session): void
    {
        $session->terms()
            ->where('status', SessionStatus::Active)
            ->update(['status' => SessionStatus::Planned]);

        $settings = SchoolSetting::query()->first();

        if ($settings?->current_academic_session_id === $session->id) {
            $settings->update([
                'current_academic_session_id' => null,
                'current_term_id' => null,
            ]);
        }
    }

    public function delete(AcademicSession $session): void
    {
        if ($session->terms()->exists() || $session->classSectionOfferings()->exists()) {
            throw ValidationException::withMessages([
                'session' => 'This academic session cannot be deleted because related academic records exist. Archive it instead.',
            ]);
        }

        $settings = SchoolSetting::query()->first();

        if ($settings?->current_academic_session_id === $session->id) {
            throw ValidationException::withMessages([
                'session' => 'The current academic session cannot be deleted.',
            ]);
        }

        $session->delete();
    }

    private function seedTerms(AcademicSession $session): void
    {
        $names = [
            1 => 'First Term',
            2 => 'Second Term',
            3 => 'Third Term',
        ];

        $count = min(3, max(2, (int) $session->term_count));

        for ($number = 1; $number <= $count; $number++) {
            $session->terms()->create([
                'name' => $names[$number],
                'term_number' => $number,
                'status' => SessionStatus::Planned,
            ]);
        }
    }

    private function sealOpeningTerm(AcademicSession $session): void
    {
        $active = $session->terms()->where('status', SessionStatus::Active)->first();
        $term = $active ?? $session->terms()->orderBy('term_number')->first();

        if ($term === null) {
            $settings = SchoolSetting::query()->first();
            if ($settings) {
                $settings->update([
                    'current_academic_session_id' => $session->id,
                    'current_term_id' => null,
                ]);
            }

            return;
        }

        if ($active === null) {
            $term->update(['status' => SessionStatus::Active]);
        }

        $settings = SchoolSetting::query()->first();

        if ($settings) {
            $settings->update([
                'current_academic_session_id' => $session->id,
                'current_term_id' => $term->id,
            ]);
        }
    }

    private function statusFrom(mixed $value): ?SessionStatus
    {
        if ($value instanceof SessionStatus) {
            return $value;
        }

        return is_string($value) ? SessionStatus::tryFrom($value) : null;
    }

    private function assertDates(mixed $startsOn, mixed $endsOn): void
    {
        if (strtotime((string) $endsOn) < strtotime((string) $startsOn)) {
            throw ValidationException::withMessages([
                'ends_on' => 'The session end date cannot precede the start date.',
            ]);
        }
    }
}
