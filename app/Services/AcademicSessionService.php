<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\CbtExam;
use App\Models\CbtExamAssignment;
use App\Models\ClassSectionOffering;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\LearningMaterial;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Promotion;
use App\Models\SchoolSetting;
use App\Models\Term;
use App\Models\TimetableSlot;
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
        DB::transaction(function () use ($session) {
            if (Enrollment::query()->where('academic_session_id', $session->id)->exists()) {
                throw ValidationException::withMessages([
                    'session' => 'This academic session cannot be deleted because pupils are enrolled on it. Archive it instead.',
                ]);
            }

            if (Promotion::query()->where('academic_session_id', $session->id)->exists()) {
                throw ValidationException::withMessages([
                    'session' => 'This academic session cannot be deleted because promotion records exist. Archive it instead.',
                ]);
            }

            if (CbtExam::query()->where('academic_session_id', $session->id)->exists()) {
                throw ValidationException::withMessages([
                    'session' => 'This academic session cannot be deleted because CBT exams reference it. Archive it instead.',
                ]);
            }

            $this->removeSessionInvoices($session);

            // Drop fee-book rows for this year.
            $session->feeStructures()->delete();

            $this->removeEmptyOfferings($session);

            $settings = SchoolSetting::query()->first();

            if ($settings?->current_academic_session_id === $session->id) {
                $settings->update([
                    'current_academic_session_id' => null,
                    'current_term_id' => null,
                ]);
            } elseif ($settings?->current_term_id
                && $session->terms()->whereKey($settings->current_term_id)->exists()) {
                $settings->update(['current_term_id' => null]);
            }

            // Keep applications; only drop the session link (session_name stays on the form).
            $session->admissionApplications()->update(['academic_session_id' => null]);

            $session->terms()->delete();
            $session->delete();
        });
    }

    /**
     * Remove invoices, line items, payments, and allocations for this year.
     */
    private function removeSessionInvoices(AcademicSession $session): void
    {
        $invoiceIds = Invoice::query()
            ->where('academic_session_id', $session->id)
            ->pluck('id');

        if ($invoiceIds->isEmpty()) {
            return;
        }

        $paymentIds = Payment::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->pluck('id');

        if ($paymentIds->isNotEmpty()) {
            PaymentAllocation::query()->whereIn('payment_id', $paymentIds)->delete();
            Payment::query()->whereIn('id', $paymentIds)->delete();
        }

        foreach (Invoice::query()->whereIn('id', $invoiceIds)->with('items')->get() as $invoice) {
            $invoice->items()->delete();
            $invoice->delete();
        }
    }

    /**
     * Drop book forms that only carry catalogue subjects / teacher appointments.
     * Forms with sealed school work must stay — archive the year instead.
     */
    private function removeEmptyOfferings(AcademicSession $session): void
    {
        $offerings = ClassSectionOffering::query()
            ->where('academic_session_id', $session->id)
            ->with(['subjectOfferings.teacherAssignments'])
            ->get();

        foreach ($offerings as $offering) {
            if ($this->offeringHasSealedWork($offering)) {
                throw ValidationException::withMessages([
                    'session' => 'This academic session cannot be deleted because forms still have attendance, timetables, assignments, or other sealed work. Archive the year instead.',
                ]);
            }

            foreach ($offering->subjectOfferings as $subjectOffering) {
                $subjectOffering->teacherAssignments()->delete();
                $subjectOffering->delete();
            }

            $offering->classTeacherAssignments()->delete();
            $offering->delete();
        }
    }

    private function offeringHasSealedWork(ClassSectionOffering $offering): bool
    {
        $id = $offering->id;

        return $offering->enrollments()->exists()
            || AttendanceRecord::query()->where('class_section_offering_id', $id)->exists()
            || TimetableSlot::query()->where('class_section_offering_id', $id)->exists()
            || Assignment::query()->where('class_section_offering_id', $id)->exists()
            || LearningMaterial::query()->where('class_section_offering_id', $id)->exists()
            || CbtExam::query()->where('class_section_offering_id', $id)->exists()
            || CbtExamAssignment::query()->where('class_section_offering_id', $id)->exists();
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
