<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\AssessmentScore;
use App\Models\Assignment;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtExamAssignment;
use App\Models\CbtResult;
use App\Models\ClassSectionOffering;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LearningMaterial;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Promotion;
use App\Models\SchoolSetting;
use App\Models\SubjectOffering;
use App\Models\SubjectTeacherAssignment;
use App\Models\Term;
use App\Models\TermResult;
use App\Models\TermSummary;
use App\Models\TimetableSlot;
use Illuminate\Database\QueryException;
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
        try {
            DB::transaction(function () use ($session) {
                $termIds = $session->terms()->pluck('id');

                $cbtBlocks = CbtExam::query()
                    ->where('academic_session_id', $session->id)
                    ->when($termIds->isNotEmpty(), fn ($q) => $q->orWhereIn('term_id', $termIds->all()))
                    ->exists();

                if ($cbtBlocks) {
                    throw ValidationException::withMessages([
                        'session' => 'This academic session cannot be deleted because CBT exams reference it. Remove those exams first, or archive the year.',
                    ]);
                }

                $this->releaseDeskPointers($session, $termIds);
                $this->removeSessionInvoices($session);
                $this->removeSessionEnrollments($session);
                $this->removeTermScopedRecords($termIds);

                FeeStructure::query()->where('academic_session_id', $session->id)->delete();
                if ($termIds->isNotEmpty()) {
                    FeeStructure::query()->whereIn('term_id', $termIds)->delete();
                }

                $this->removeSessionOfferings($session);

                // Keep applications; only drop the session link (session_name stays on the form).
                $session->admissionApplications()->update(['academic_session_id' => null]);

                if ($termIds->isNotEmpty()) {
                    Term::query()->whereIn('id', $termIds)->delete();
                }

                $session->delete();
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'session' => 'This academic session could not be deleted. '.$this->friendlyConstraintMessage($exception),
            ]);
        }
    }

    private function friendlyConstraintMessage(QueryException $exception): string
    {
        $message = $exception->getMessage();

        if (preg_match('/constraint fails \(`[^`]+`\.`([^`]+)`/i', $message, $matches) === 1) {
            return 'Related rows remain in '.$matches[1].'. Archive the year if those records must be kept.';
        }

        return 'Related ledger rows are still linked. Archive the year if those records must be kept.';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int|string>  $termIds
     */
    private function releaseDeskPointers(AcademicSession $session, $termIds): void
    {
        SchoolSetting::query()
            ->where(function ($query) use ($session, $termIds): void {
                $query->where('current_academic_session_id', $session->id);
                if ($termIds->isNotEmpty()) {
                    $query->orWhereIn('current_term_id', $termIds->all());
                }
            })
            ->update([
                'current_academic_session_id' => null,
                'current_term_id' => null,
            ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int|string>  $termIds
     */
    private function removeTermScopedRecords($termIds): void
    {
        if ($termIds->isEmpty()) {
            return;
        }

        $ids = $termIds->all();

        $scoreIds = AssessmentScore::query()->whereIn('term_id', $ids)->pluck('id');
        if ($scoreIds->isNotEmpty()) {
            CbtResult::query()->whereIn('assessment_score_id', $scoreIds)->update(['assessment_score_id' => null]);
            AssessmentScore::query()->whereIn('id', $scoreIds)->delete();
        }

        TermResult::query()->whereIn('term_id', $ids)->delete();
        TermSummary::query()->whereIn('term_id', $ids)->delete();
        TimetableSlot::query()->whereIn('term_id', $ids)->delete();
    }

    /**
     * @return array{enrollments: int, invoices: int, fee_structures: int, forms: int, promotions: int, cbt_exams: int}
     */
    public function footprint(AcademicSession $session): array
    {
        return [
            'enrollments' => Enrollment::query()->where('academic_session_id', $session->id)->count(),
            'invoices' => Invoice::query()->where('academic_session_id', $session->id)->count(),
            'fee_structures' => $session->feeStructures()->count(),
            'forms' => $session->classSectionOfferings()->count(),
            'promotions' => Promotion::query()->where('academic_session_id', $session->id)->count(),
            'cbt_exams' => CbtExam::query()->where('academic_session_id', $session->id)->count(),
        ];
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

        $itemIds = InvoiceItem::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->pluck('id');

        $paymentIds = Payment::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->pluck('id');

        if ($paymentIds->isNotEmpty()) {
            PaymentAllocation::query()->whereIn('payment_id', $paymentIds)->delete();
        }

        if ($itemIds->isNotEmpty()) {
            PaymentAllocation::query()->whereIn('invoice_item_id', $itemIds)->delete();
            InvoiceItem::query()->whereIn('id', $itemIds)->delete();
        }

        if ($paymentIds->isNotEmpty()) {
            Payment::query()->whereIn('id', $paymentIds)->delete();
        }

        Invoice::query()->whereIn('id', $invoiceIds)->delete();
    }

    /**
     * Remove roll history for this year (including withdrawn rows left after pupil removal).
     */
    private function removeSessionEnrollments(AcademicSession $session): void
    {
        $enrollmentIds = Enrollment::query()
            ->where('academic_session_id', $session->id)
            ->pluck('id');

        if ($enrollmentIds->isNotEmpty()) {
            $this->removeAttendanceForEnrollments($enrollmentIds->all());

            $scoreIds = AssessmentScore::query()
                ->whereIn('enrollment_id', $enrollmentIds)
                ->pluck('id');
            if ($scoreIds->isNotEmpty()) {
                CbtResult::query()->whereIn('assessment_score_id', $scoreIds)->update(['assessment_score_id' => null]);
                AssessmentScore::query()->whereIn('id', $scoreIds)->delete();
            }

            TermResult::query()->whereIn('enrollment_id', $enrollmentIds)->delete();
            TermSummary::query()->whereIn('enrollment_id', $enrollmentIds)->delete();
            CbtAttempt::query()->whereIn('enrollment_id', $enrollmentIds)->update(['enrollment_id' => null]);
            Invoice::query()->whereIn('enrollment_id', $enrollmentIds)->update(['enrollment_id' => null]);

            Promotion::query()
                ->where(function ($query) use ($enrollmentIds): void {
                    $query->whereIn('from_enrollment_id', $enrollmentIds)
                        ->orWhereIn('to_enrollment_id', $enrollmentIds);
                })
                ->delete();

            Enrollment::query()->whereIn('id', $enrollmentIds)->delete();
        }

        Promotion::query()->where('academic_session_id', $session->id)->delete();
    }

    /**
     * @param  list<int|string>  $enrollmentIds
     */
    private function removeAttendanceForEnrollments(array $enrollmentIds): void
    {
        if ($enrollmentIds === []) {
            return;
        }

        $attendanceIds = AttendanceRecord::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->pluck('id');

        $this->removeAttendanceRecords($attendanceIds->all());
    }

    /**
     * @param  list<int|string>  $attendanceIds
     */
    private function removeAttendanceRecords(array $attendanceIds): void
    {
        if ($attendanceIds === []) {
            return;
        }

        AttendanceCorrection::query()->whereIn('attendance_record_id', $attendanceIds)->delete();
        AttendanceRecord::query()->whereIn('id', $attendanceIds)->delete();
    }

    /**
     * Drop forms for this year, including subject offerings and light class work.
     */
    private function removeSessionOfferings(AcademicSession $session): void
    {
        $offeringIds = ClassSectionOffering::query()
            ->where('academic_session_id', $session->id)
            ->pluck('id');

        if ($offeringIds->isEmpty()) {
            return;
        }

        $ids = $offeringIds->all();

        $attendanceIds = AttendanceRecord::query()
            ->whereIn('class_section_offering_id', $ids)
            ->pluck('id');
        $this->removeAttendanceRecords($attendanceIds->all());

        TimetableSlot::query()->whereIn('class_section_offering_id', $ids)->delete();
        Assignment::query()->whereIn('class_section_offering_id', $ids)->delete();
        LearningMaterial::query()->whereIn('class_section_offering_id', $ids)->delete();
        CbtExamAssignment::query()->whereIn('class_section_offering_id', $ids)->delete();

        if (CbtExam::query()->whereIn('class_section_offering_id', $ids)->exists()) {
            throw ValidationException::withMessages([
                'session' => 'This academic session cannot be deleted because CBT exams reference its forms. Remove those exams first, or archive the year.',
            ]);
        }

        $subjectOfferingIds = SubjectOffering::query()
            ->whereIn('class_section_offering_id', $ids)
            ->pluck('id');

        if ($subjectOfferingIds->isNotEmpty()) {
            SubjectTeacherAssignment::query()
                ->whereIn('subject_offering_id', $subjectOfferingIds)
                ->delete();
            SubjectOffering::query()->whereIn('id', $subjectOfferingIds)->delete();
        }

        \App\Models\ClassTeacherAssignment::query()
            ->whereIn('class_section_offering_id', $ids)
            ->delete();

        ClassSectionOffering::query()->whereIn('id', $ids)->delete();
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
